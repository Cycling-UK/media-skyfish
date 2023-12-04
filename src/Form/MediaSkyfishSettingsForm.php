<?php

namespace Drupal\media_skyfish\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\media_skyfish\ApiService;
use Drupal\media_skyfish\ConfigService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class MediaSkyfishSettingsForm.
 */
class MediaSkyfishSettingsForm extends ConfigFormBase {

  /**
   * Skyfish config service.
   *
   * @var \Drupal\media_skyfish\ConfigService
   */
  protected $config;

  /**
   * Skyfish api service.
   *
   * @var \Drupal\media_skyfish\ApiService
   */
  protected $connect;

  /**
   * Default amount of images per page if no are set.
   *
   * @var int
   */
  protected const DEFAULT_IMAGES_PER_PAGE = 10;

  /**
   * Default cache time in minutes for skyfish API.
   *
   * @var int
   */
  protected const DEFAULT_CACHE_TIME = 60;

  /**
   * {@inheritdoc}
   */
  public function __construct(ConfigFactoryInterface $config_factory, EntityTypeManagerInterface $entity_type_manager, ConfigService $config, ApiService $connect) {
    parent::__construct($config_factory);
    $this->config = $config;
    $this->connect = $connect;
  }


  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('entity_type.manager'),
      $container->get('media_skyfish.configservice'),
      $container->get('media_skyfish.apiservice')
    );
  }

  /**
   * Skyfish configs.
   *
   * @return array
   *   Array of Skyfish configs.
   */
  protected function getEditableConfigNames() {
    return [
      'media_skyfish.adminconfig',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'media_skyfish_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('media_skyfish.adminconfig');
    $form['skyfish_global_api'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Skyfish Global API'),
    ];
    $form['skyfish_global_api']['media_skyfish_global_user'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Skyfish Username'),
      '#description' => $this->t('Please enter username to login to Skyfish.'),
      '#maxlength' => 128,
      '#size' => 128,
      '#default_value' => $config->get('media_skyfish_global_user'),
    ];
    $form['skyfish_global_api']['media_skyfish_global_password'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Skyfish Password'),
      '#description' => $this->t('Please enter password to login to Skyfish.'),
      '#maxlength' => 128,
      '#size' => 128,
      '#default_value' => $config->get('media_skyfish_global_password'),
    ];
    $form['skyfish_global_api']['media_skyfish_api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Skyfish API Key'),
      '#description' => $this->t('Please enter Skyfish API Key here.'),
      '#maxlength' => 128,
      '#size' => 128,
      '#default_value' => $config->get('media_skyfish_api_key'),
    ];
    $form['skyfish_global_api']['media_skyfish_api_secret'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Skyfish API Secret'),
      '#description' => $this->t('Please enter Skyfish API secret key.'),
      '#maxlength' => 128,
      '#size' => 128,
      '#default_value' => $config->get('media_skyfish_api_secret'),
    ];
    $form['skyfish_global_api']['media_skyfish_cache'] = [
      '#type' => 'textfield',
      '#attributes' => [
        'type' => 'number',
        'min' => 0,
        'max' => 999,
        'step' => 1,
      ],
      '#title' => $this->t('Cache time in minutes'),
      '#description' => $this->t('Set how long images will be saved in cache.'),
      '#maxlength' => 3,
      '#default_value' => $config->get('media_skyfish_cache') ?? self::DEFAULT_CACHE_TIME,
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);

    // Set input values temporarily for the ConfigService.
    $this->config->setKey($form_state->getValue('media_skyfish_api_key'));
    $this->config->setSecret($form_state->getValue('media_skyfish_api_secret'));
    $this->config->setUsername($form_state->getValue('media_skyfish_global_user'));
    $this->config->setPassword($form_state->getValue('media_skyfish_global_password'));

    // Check if using input values it is possible to authorize with Skyfish API.
    if (!$this->connect->getToken()) {
      $form_state->setError($form, 'Incorrect login information: check Username, Password and API key.');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    parent::submitForm($form, $form_state);
    $this->config('media_skyfish.adminconfig')
      ->set('media_skyfish_api_key', $form_state->getValue('media_skyfish_api_key'))
      ->set('media_skyfish_api_secret', $form_state->getValue('media_skyfish_api_secret'))
      ->set('media_skyfish_global_user', $form_state->getValue('media_skyfish_global_user'))
      ->set('media_skyfish_global_password', $form_state->getValue('media_skyfish_global_password'))
      ->set('media_skyfish_cache', $form_state->getValue('media_skyfish_cache'))
      ->save();
  }

}
