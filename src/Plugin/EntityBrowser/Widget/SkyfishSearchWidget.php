<?php

namespace Drupal\media_skyfish\Plugin\EntityBrowser\Widget;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\Exception\FileException;
use Drupal\Core\File\Exception\InvalidStreamWrapperException;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\FileTransfer\FileTransferException;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\ByteSizeMarkup;
use Drupal\entity_browser\Annotation\EntityBrowserWidget;
use Drupal\entity_browser\WidgetBase;
use Drupal\entity_browser\WidgetValidationManager;
use Drupal\entity_browser\Element\EntityBrowserPagerElement;
use Drupal\file\FileInterface;
use Drupal\media_skyfish\ApiService;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Adds an upload field browser's widget.
 *
 * @EntityBrowserWidget(
 *   id = "media_skyfish_search",
 *   label = @Translation("Skyfish search"),
 *   description = @Translation("Adds a Skyfish search widget."),
 *   auto_select = FALSE
 * )
 */
class SkyfishSearchWidget extends WidgetBase {

  /**
   * Drupal logger service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * Skyfish api service.
   *
   * @var \Drupal\media_skyfish\ApiService
   */
  protected ApiService $connect;

  /**
   * SkyfishWidget constructor.
   *
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EventDispatcherInterface $event_dispatcher, EntityTypeManagerInterface $entity_type_manager, WidgetValidationManager $validation_manager, LoggerInterface $logger, ApiService $api_service) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $event_dispatcher, $entity_type_manager, $validation_manager);
    $this->logger = $logger;
    $this->connect = $api_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): SkyfishSearchWidget {
    /** @noinspection PhpParamsInspection */
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('event_dispatcher'),
      $container->get('entity_type.manager'),
      $container->get('plugin.manager.entity_browser.widget_validation'),
      $container->get('logger.channel.media_skyfish'),
      $container->get('media_skyfish.apiservice')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
        'root_folder_id' => 0,
        'omit_folder_ids' => [],
        'media_types' => [],
      ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form = parent::buildConfigurationForm($form, $form_state);
    $keyed_folders = $this->getFolderTree();
    // Drop-down list of top three levels of folders.
    $folder_options = [0 => $this->t('-- Show all folders --')];
    foreach ($keyed_folders as $id => $folder) {
      $folder_options[$id] = $folder->name;
    }
    // Selection list for root folder.
    $form['root_folder_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Skyfish root folder'),
      '#default_value' => $this->configuration['root_folder_id'],
      '#options' => $folder_options,
      '#description' => $this->t('Limit choices to those within this top-level Skyfish folder.'),
    ];
    // Checkboxes for folders to omit.
    unset($folder_options[0]);
    $form['omit_folder_ids'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Omit top-level Skyfish folders'),
      '#default_value' => $this->configuration['omit_folder_ids'],
      '#options' => $folder_options,
      '#description' => $this->t('Do not show items from these Skyfish folders.'),
    ];
    // Media types to return.
    $media_type_options = [
      'image' => $this->t("Images"),
      'vector' => $this->t("Vector graphics"),
      'video' => $this->t("Videos"),
      'generic' => $this->t("Generic"),
    ];
    $form['media_types'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Media types to request from Skyfish'),
      '#default_value' => $this->configuration['media_types'],
      '#options' => $media_type_options,
      '#description' => $this->t('Select Skyfish file types, or leave empty to request all.'),
    ];
    return $form;
  }

  /**
   * @inheritdoc
   */
  public function getForm(array &$original_form, FormStateInterface $form_state, array $additional_widget_parameters): array {

    $form = parent::getForm($original_form, $form_state, $additional_widget_parameters);

    $keyed_folders = $this->getFolderTree();
    $root_folder_name = 'all folders';

    // Drop-down list of top THREE levels of folders.
    $root_folder_id = $this->configuration['root_folder_id'];
    if ($root_folder_id && array_key_exists($root_folder_id, $keyed_folders)) {
      $root_folder = $keyed_folders[$root_folder_id];
      $root_folder_name = $root_folder->name;
      $keyed_folders = $root_folder->children;
      $folder_options[$root_folder->id] = '-- All folders --';
    }
    else {
      $folder_options = ['' => '-- All folders --'];
    }
    foreach ($keyed_folders as $folder) {
      if (in_array($folder->id, $this->configuration['omit_folder_ids'])) {
        continue;
      }
      $folder_options[$folder->id] = $folder->name;
      if (isset($folder->children)) {
        foreach ($folder->children as $child) {
          $folder_options[$child->id] = $folder->name . ' | ' . $child->name;
          if (isset($child->children)) {
            foreach ($child->children as $grandchild) {
              $folder_options[$grandchild->id] = $folder->name . ' | ' . $child->name . ' | ' . $grandchild->name;
            }
          }
        }
      }
    }

    $media_types = $this->configuration['media_types'];
    $media_type_names = [];
    foreach ($media_types as $type => $value) {
      if ($value) {
        $media_type_names[] = $type;
      }
    }
    if (count($media_type_names)) {
      $info_text = $this->t('Items in %folder, of type %media_types.', [
        '%folder' => $root_folder_name,
        '%media_types' => implode(' or ', $media_type_names),
      ]);
    }
    else {
      $info_text = $this->t('Items in %folder, of any type.', [
        '%folder' => $root_folder_name,
        '%media_types' => implode(' or ', $media_type_names),
      ]);
    }
    $form['settings_info'] = [
      '#title' => $this->t('Skyfish'),
      '#type' => 'item',
      '#markup' => $info_text,
    ];

    $form['folder'] = [
      '#type' => 'select',
      '#title' => 'Limit to Sub-folder',
      '#options' => $folder_options,
    ];

    // Search form section.
    $this->buildSearchForm($form, $form_state);
    // Results form section.
    if ($form_state->getValue('search_button') || ($form_state->getTriggeringElement() && $form_state->getTriggeringElement()['#array_parents'][1] === 'pager')) {
      $form = $this->buildResultsForm($form_state, $form);
    }
    else {
      unset($form['actions']);
    }
    return $form;
  }

  /**
   * @inheritdoc
   */
  public function submit(array &$element, array &$form, FormStateInterface $form_state): void {
    $form_values = $form_state->getValues();
    $media = [];
    // Get item_id keys where value is non-zero (selected).
    $selected_item_ids = [];
    foreach ($form_values['items'] as $item_id) {
      if ($item_id != 0) {
        $selected_item_ids[] = $item_id;
      }
    }
    // Get media item data.
    foreach ($selected_item_ids as $selected_item_id) {
      $media_item = $this->connect->getItem($selected_item_id);
      $media[$selected_item_id] = $media_item;
    }
    // Save the media items.
    $saved_items = $this->saveItems($media);
    // Pass selected items to the entity field they are to be added to.
    $this->selectEntities($saved_items, $form_state);
  }

  /**
   * Return Form API elements for the Search form.
   *
   * @param $form
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *
   * @return void
   */
  protected function buildSearchForm(&$form, FormStateInterface $form_state): void {
    $form['search_string'] = [
      '#title' => $this->t('Search'),
      '#type' => 'textfield',
      '#default_value' => $form_state->get('search_string'),
      '#description' => $this->t('Enter search terms/keywords, or leave empty to browse all. Prefix any term with "-" to exclude results with that term.'),
    ];
    $form['results_order'] = [
      '#title' => $this->t('Results order'),
      '#type' => 'radios',
      '#options' => ['created' => 'Date (most recent first)', 'relevance' => 'Search keywords relevance'],
      '#default_value' => $form_state->get('results_order') ?? 'created',
    ];
    $form['search_button'] = [
      '#type' => 'button',
      '#name' => 'search',
      '#value' => $this->t('Search / Browse'),
    ];
  }

  /**
   * Save Skyfish items in array as new Media entities.
   *
   * @param array $items
   *   Skyfish items.
   *
   * @return array
   *   Array of media items.
   */
  protected function saveItems(array $items): array {
    try {
      $media_storage = $this->entityTypeManager->getStorage('media');
    } catch (InvalidPluginDefinitionException|PluginNotFoundException $e) {
      $this->logger->error("Error saving media items: " . $e->getMessage());
      return [];
    }
    foreach ($items as $item_id => $item) {
      $this->logger->info(print_r($item, TRUE));
      // Check to see if we have already grabbed this file.
      $query = \Drupal::entityQuery('media')
        ->condition('skyfish_id', $item_id)
        ->accessCheck(FALSE);
      $media_ids = $query->execute();
      $media_items = $media_storage->loadMultiple($media_ids);
      if (count($media_items)) {
        // We've already downloaded this item.
        $items[$item_id] = reset($media_items);
      }
      else {
        // We haven't downloaded this item before.
        $file = $this->downloadSkyfishFile($item);
        $media_data = [];
        $alt_text = '';
        if (isset($item->metadata->title)) {
          // Get first title of any language.
          $alt_text = reset($item->metadata->title);
        }
        if (!$alt_text) {
          $alt_text = $item->filename;
        }
        switch ($item->type) {
          case 'stock':
          case 'image':
          case 'vector':
            $media_data['bundle'] = 'image';
            $media_data['field_media_image'] = ['target_id' => $file->id(), 'alt' => $alt_text];
            break;

          case 'video':
            $media_data['bundle'] = 'video';
            $media_data['field_media_video_file'] = $file;
            break;

          case 'generic':
            $media_data['bundle'] = 'document';
            $media_data['field_media_document'] = $file;
            break;
        }
        $media_data['skyfish_id'] = $item_id;
        try {
          $media_item = $media_storage->create($media_data);
          $media_item->save();
          $items[$item_id] = $media_item;
        } catch (\Exception $e) {
          $this->logger->error("Error saving media item: " . $e->getMessage());
        }
      }
    }
    return $items;
  }

  /**
   * Save file in the system.
   *
   * @param \stdClass $item
   *   Skyfish item.
   *
   * @return \Drupal\file\FileInterface|false
   *   Saved file details.
   */
  protected function downloadSkyfishFile(\stdClass $item): bool|FileInterface {
    $folder = \Drupal::config('system.file')
        ->get('default_scheme') . '://media-skyfish/';
    // Create directory for user files.
    \Drupal::service('file_system')
      ->prepareDirectory($folder, FileSystemInterface::CREATE_DIRECTORY);
    // Download URL is temporary, only lasts for 5 minutes.
    $download_url = $this->connect->getItemDownloadUrl($item->id);
    // Save file in the system from the url.
    try {
      $data = (string) \Drupal::httpClient()->get($download_url)->getBody();
      // For managed files, use this:
      $file = \Drupal::service('file.repository')->writeData($data, $folder.$item->filename, FileExists::Replace);
    }
    catch (FileTransferException $e) {
      \Drupal::messenger()->addError(t('Failed to fetch file due to error "%error"', ['%error' => $e->getMessage()]));
    }
    catch (FileException | InvalidStreamWrapperException $e) {
      \Drupal::messenger()->addError(t('Failed to save file due to error "%error"', ['%error' => $e->getMessage()]));
    }
    // Return the saved file details.
    return $file;
  }

  /**
   * Construct Form API elements for search results.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   * @param array $form
   *
   * @return array
   */
  public function buildResultsForm(FormStateInterface $form_state, array $form): array {
    if ($form_state->getValue('search_button')) {
      $form_state->set('search_string', $form_state->getValue('search_string'));
      $form_state->set('search_folder', $form_state->getValue('folder'));
      $form_state->set('results_order', $form_state->getValue('results_order'));
      EntityBrowserPagerElement::setCurrentPage($form_state);
    }
    $page = EntityBrowserPagerElement::getCurrentPage($form_state);
    $per_page = 20;
    $offset = ($page - 1) * $per_page;
    $this->connect->setSearchOffsetCount($offset, $per_page);
    $this->connect->setSearchFolderIds($form_state->get('search_folder'));
    $this->connect->setResultsOrder($form_state->get('results_order'));
    $media_types = [];
    foreach ($this->configuration['media_types'] as $key => $value) {
      if ($value) {
        $media_types[] = $key;
      }
    }
    $this->connect->setSearchMediaTypes($media_types);
    $search_result = $this->connect->getResultsForSearch($form_state->get('search_string'));
    $total_found = $search_result['total_found'];
    $item_count = $search_result['item_count'];
    $results_offset = $search_result['results_offset'];
    $total_pages = ceil($total_found / $per_page);
    $found_items = $search_result['media'];
    $values = [
      '@total' => $total_found,
      '@showing' => $item_count,
      '@start' => $offset + 1,
      '@end' => $offset + $item_count,
      '@page' => $page,
      '@pages' => $total_pages,
    ];
    if ($results_offset == 0) {
      if ($total_found == 0) {
        $results_message = $this->t('Found no items for this search.');
      }
      elseif ($total_found == 1) {
        $results_message = $this->t('Found <strong>one item</strong> for this search:');
      }
      elseif ($total_found == $item_count) {
        $results_message = $this->t('Found <strong>@total items</strong>, showing all @showing:', $values);
      }
      else {
        $results_message = $this->t('Found <strong>@total items</strong>, showing items 1 to @showing (page 1 of @pages):', $values);
      }
    }
    else {
      $results_message = $this->t('Found <strong>@total items</strong>, showing @start to @end (page @page of @pages):', $values);
    }
    $form['result_count'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $results_message,
    ];
    $options = [];
    foreach ($found_items as $found_item) {
      $item = [
        '#type' => 'html_tag',
        '#tag' => 'img',
        '#attributes' => [
          'src' => $found_item->thumbnail_url_ssl,
          'title' => $found_item->filename,
          'alt' => '',
          'width' => '320',
          'style' => 'max-width: none',
        ],
      ];
      $folder_names = $this->getFolderPathnames($found_item->folder_ids);
      $folder_list = ['#theme' => 'item_list', '#items' => $folder_names];
      $keywords = $found_item->keywords ?? [''];
      $keyword_list = ['#theme' => 'item_list', '#items' => $keywords];
      $filename = $found_item->filename ?? '';
      $file_size = $found_item->file_disksize ?? '';
      $width = $found_item->width ?? '';
      if ($width) {
        $height = $found_item->height ?? '';
        $megapixels = number_format(($width * $height) / 1e6, 1);
      }
      $title = $found_item->title ?? '';
      $description = $found_item->description ?? '';
      $byline = $found_item->byline ?? '-';
      $copyright = $found_item->copyright ?? '';
      $created = substr($found_item->created, 0, 10);
      $renderer = \Drupal::service('renderer');
      $data_html = '<div class="info"><dl class="popup">';
      $data_html .= '<dt>Created</dt><dd>' . $created . '</dd>';
      $data_html .= '<dt>Title</dt><dd>' . $title . '</dd>';
      $data_html .= '<dt>Description</dt><dd>' . $description . '</dd>';
      $data_html .= '<dt>Byline</dt><dd>' . $byline . '</dd>';
      $data_html .= '<dt>Copyright</dt><dd>' . $copyright . '</dd>';
      $data_html .= '<dt>Keywords</dt><dd>' . $renderer->render($keyword_list) . '</dd>';
      $data_html .= '<dt>Filename</dt><dd>' . $filename . '</dd>';
      $data_html .= '<dt>File size</dt><dd>' . ByteSizeMarkup::create($file_size) . '</dd>';
      if ($width) {
        $data_html .= '<dt>Pixels</dt><dd>' . $width . '×' . $height . ' (' . $megapixels . ' Mpixels)</dd>';
      }
      $data_html .= '<dt>Folders</dt><dd>' . $renderer->render($folder_list) . '</dd>';
      $data_html .= '</dl></div>';
      $options[$found_item->unique_media_id] = $renderer->render($item) . $data_html;
    }
    $form['results'] = ['#type' => 'container', '#attributes' => ['class' => ['skyfish-results']]];
    $form['results']['items'] = [
      '#type' => 'checkboxes',
      '#options' => $options,
    ];
    if ($total_pages > 1) {
      $form['pager'] = [
        '#type' => 'entity_browser_pager',
        '#total_pages' => $total_pages,
      ];
    }
    $form['#attached']['library'][] = 'media_skyfish/item_display';
    return $form;
  }

  /**
   * Get tree of folders, sorted by folder names.
   *
   * @return array
   */
  public function getFolderTree(): array {
    $keyed_folders = $this->getKeyedFolders();
    // Re-arrange into tree structure.
    $orphans = TRUE;
    while ($orphans) {
      $orphans = FALSE;
      foreach ($keyed_folders as $key => $folder) {
        $children = FALSE;
        foreach ($keyed_folders as $f) {
          // Any folders with this as a parent?
          if ($f->parent != NULL && $f->parent == $key) {
            $children = TRUE;
            $orphans = TRUE;
            break;
          }
        }
        // $keyed_folders[$key] has no children, so we can move it under parent.
        if (!$children && $folder->parent != NULL && isset($keyed_folders[$folder->parent])) {
          $keyed_folders[$folder->parent]->children[$key] = $folder;
          $keyed_folders[$folder->parent]->folder_ids = [
            ...$keyed_folders[$folder->parent]->folder_ids,
            ...$folder->folder_ids,
          ];
          unset($keyed_folders[$key]);
          // Sort by folder name.
          uasort($keyed_folders[$folder->parent]->children, static fn($a, $b) => $a->name <=> $b->name);
        }
      }
    }
    return $keyed_folders;
  }

  /**
   * Return the full folder path names for the given folder IDs.
   *
   * @param array $folder_ids
   *
   * @return array
   */
  protected function getFolderPathnames(array $folder_ids): array {
    $folder_names = [];
    $keyed_folders = $this->getKeyedFolders();
    foreach ($folder_ids as $folder_id) {
      $names = [];
      $folder = $keyed_folders[$folder_id];
      do {
        $names[] = $folder->name;
        $parent = $folder->parent;
        if ($parent) {
          $folder = $keyed_folders[$parent];
        }
      } while ($parent);
      // Skip folder if root folder is set and this folder has a different root.
      if ($this->configuration['root_folder_id'] != 0 && $this->configuration['root_folder_id'] != $folder->id) {
        continue;
      }
      // Add the top-level folder name if we haven't set a root folder.
      if ($this->configuration['root_folder_id'] == 0) {
        $names[] = $folder->name;
      }
      $folder_names[] = implode(' | ', array_reverse($names));
    }
    return $folder_names;
  }

  /**
   * Get flat list of all folders, keyed by the folder IDs (omitting Trash).
   *
   * @return array
   */
  protected function getKeyedFolders(): array {
    $keyed_folders = [];
    $folders = $this->connect->getFolders();
    if (is_array($folders)) {
      foreach ($folders as $folder) {
        $folder->folder_ids = [$folder->id];
        $keyed_folders[$folder->id] = $folder;
      }
    }
    if (count($keyed_folders) === 0) {
      \Drupal::messenger()
        ->addError($this->t('No folders found. Check Skyfish user permissions and settings: <a href="@link">@link</a>', ['@link' => '/admin/config/media/media_skyfish']));
    }
    return $keyed_folders;
  }

  /**
   * Prepare entities for validation.
   *
   * {@inheritDoc}
   */
  protected function prepareEntities(array $form, FormStateInterface $form_state): array {
    // @todo Implement prepareEntities() method?
    return [];
  }

}
