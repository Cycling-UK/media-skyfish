<?php

namespace Drupal\media_skyfish;

use Drupal;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;
use Psr\Log\LoggerInterface;
use stdClass;

/**
 * Service that connects to and get data from Skyfish.
 *
 * @package Drupal\media_skyfish
 */
class ApiService {

  use StringTranslationTrait;

  /**
   * Base url for service.
   */
  public const API_BASE_URL = 'https://api.colourbox.com';

  /**
   * Folders uri.
   */
  public const API_URL_FOLDER = '/folder?sort_by=name';

  /**
   * Uri for searching folders.
   */
  public const API_URL_SEARCH = '/search?&return_values=title+unique_media_id+thumbnail_url&folder_ids=';

  /**
   * Http client.
   *
   * @var \GuzzleHttp\Client
   */
  protected Client $client;

  /**
   * Header for authorization.
   *
   * @var bool|string
   */
  protected $header;

  /**
   * Current user service.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected AccountInterface $account;

  /**
   * Drupal logger service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * Cache limit in minutes.
   *
   * @var int|null
   */
  protected ?int $cache;

  /**
   * @var \Drupal\media_skyfish\ConfigService
   */
  private ConfigService $config;

  /**
   * @var string Folder ID list for Search.
   */
  private string $search_folder_ids = '';

  private int $search_offset = 0;

  private int $search_count = 20;

  private array $search_media_types = [];

  private string $results_order = 'relevance';

  /**
   * Construct ApiService.
   *
   * @param \GuzzleHttp\Client $client
   *   Http client.
   * @param \Drupal\media_skyfish\ConfigService $config_service
   *   Config service for Skyfish API authorization and settings.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   Drupal user account interface.
   * @param \Psr\Log\LoggerInterface $logger
   *   Logger service.
   *
   * @throws \Exception
   */
  public function __construct(Client $client, ConfigService $config_service, AccountInterface $account, LoggerInterface $logger) {
    $this->config = $config_service;
    $this->client = $client;
    $this->header = $this->getHeader();
    $this->account = $account;
    $this->cache = $this->config->getCacheTime();
    $this->logger = $logger;
  }

  /**
   * Get token from a Skyfish.
   *
   * @return string|bool
   *   Authorization token string or false if there was an error.
   */
  public function getToken() {
    try {
      $request = $this
        ->client
        ->request('POST',
          self::API_BASE_URL . '/authenticate/userpasshmac',
          [
            'json' =>
              [
                'username' => $this->config->getUsername() ?? '',
                'password' => $this->config->getPassword() ?? '',
                'key' => $this->config->getKey() ?? '',
                'ts' => time(),
                'hmac' => $this->config->getHmac() ?? '',
              ],
          ]);
    } catch (GuzzleException $e) {
      return FALSE;
    }
    if ($request->getStatusCode() !== 200) {
      $this->handleRequestError($request->getStatusCode());
      return FALSE;
    }
    try {
      $response = json_decode($request->getBody()->getContents(), TRUE, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
    }
    return $response['token'] ?? FALSE;
  }

  /**
   * Get authorization header.
   *
   * @return bool|string
   *   Authorization header for further communication, or false if error.
   */
  public function getHeader() {
    $token = $this->getToken();

    if (!$token) {
      return FALSE;
    }

    return 'CBX-SIMPLE-TOKEN Token=' . $token;
  }

  /**
   * Make request to Skyfish API.
   *
   * @param string $uri
   *   Request URL.
   *
   * @return array|object|bool
   *   Response body content.
   */
  protected function doRequest(string $uri) {
    try {
      #\Drupal::messenger()->addMessage("GET " . self::API_BASE_URL . $uri);
      #\Drupal::messenger()->addMessage("Authorization: " . $this->header);
      $request = $this
        ->client
        ->request(
          'GET',
          self::API_BASE_URL . $uri,
          [
            'headers' => [
              'Authorization' => $this->header,
            ],
          ]
        );
      if ($request->getStatusCode() !== 200) {
        $this->handleRequestError($request->getStatusCode());
        return FALSE;
      }
    } catch (GuzzleException $e) {
      $this->logger->error('Guzzle Exception: ' . $e->getMessage());
    }
    if (isset($request)) {
      try {
        #\Drupal::messenger()->addMessage($request->getBody());
        return json_decode($request->getBody(), FALSE, 512, JSON_THROW_ON_ERROR);
      } catch (Exception $e) {
        return FALSE;
      }
    }
    return FALSE;
  }

  /**
   * Log error on request error.
   *
   * @param int $status_code
   *   HTTP status code.
   */
  protected function handleRequestError(int $status_code): void {
    $messages = [
      400 => 'Your request contains bad syntax and the API could not understand it.',
      401 => 'You need to be logged in to access the resource',
      403 => 'You do not have access to this resource. It will help to authenticate.',
      404 => 'The requested resource does not exist. This is also returned if the method is not allowed on the resource.',
      409 => 'We encountered a conflict when trying to process your update. Try applying your update again.',
      500 => 'We encountered a problem parsing your request and can not say what went wrong. Please provide us with the “X-Cbx-Request-Id” from the response as it will help us debug the problem.',
    ];
    if (isset($messages[$status_code])) {
      $this->logger->error($messages[$status_code]);
    }
    else {
      $this->logger->error("Unknown status code: " . $status_code);
    }
  }

  /**
   * Get media cached folders from Skyfish API.
   *
   * @return array $folders
   *   Array of Skyfish folders.
   */
  public function getFolders() {
    $cache_id = 'folders_' . $this->account->id();
    $cache = Drupal::cache()->get($cache_id);
    if (empty($cache->data)) {
      $folders = $this->getFoldersWithoutCache();
      if (!empty($folders)) {
        Drupal::cache()->set($cache_id, $folders, $this->cache);
      }
      return $folders;
    }
    return $cache->data;
  }

  /**
   * Get folders from Skyfish API.
   *
   * @return array $folders
   *   Array of Skyfish folders.
   */
  public function getFoldersWithoutCache() {
    return $this->doRequest(self::API_URL_FOLDER);
  }

  /**
   * Limit search to a list of folder IDs.
   *
   * @param string $folder_ids List of folder IDs for Search.
   *
   * @return void
   */
  public function setSearchFolderIds(string $folder_ids = ''): void {
    $this->search_folder_ids = $folder_ids;
  }

  public function setSearchOffsetCount(int $offset = 0, int $count = 20) {
    $this->search_offset = $offset;
    $this->search_count = $count;
    $this->config->setItemsPerPage($count);
  }

  public function setSearchMediaTypes(array $media_types) {
    $this->search_media_types = $media_types;
  }

  public function setResultsOrder(string $order) {
    $this->results_order = $order;
  }

  public function getResultsForSearch($search_string): array {
    $string = '/search?';
    if ($search_string) {
      $string .= 'q=' . urlencode($search_string);
    }
    $query_options = ['media_count=' . $this->search_count];
    $query_options[] = 'recursive=true';
    $query_options[] = 'return_values=title+description+byline+copyright+unique_media_id+thumbnail_url_ssl+keywords+filename+created+folder_ids+file_disksize+width+height';
    $query_options[] = 'order=' . $this->results_order;
    // ToDo: user-settings for thumbnail size?
    $query_options[] = 'thumbnail_size=320px';
    if ($this->search_folder_ids) {
      $query_options[] = 'folder_ids=' . $this->search_folder_ids;
    }
    if (count($this->search_media_types)) {
      $query_options[] = 'media_type=' . implode('+', $this->search_media_types);
    }
    if ($this->search_offset) {
      $query_options[] = 'media_offset=' . $this->search_offset;
    }
    $string .= '&' . implode('&', $query_options);
    $response = $this->doRequest($string);
    if (!isset($response->response->media)) {
      return [
        'total_found' => 0,
        'item_count' => 0,
        'results_offset' => 0,
        'media' => [],
      ];
    }
    $media = $response->response->media;
    return [
      'total_found' => $response->response->hits,
      'item_count' => $response->media_count,
      'results_offset' => $response->media_offset,
      'media' => $media,
    ];
  }

  public function getItem(int $item_id) {
    return $this->doRequest('/media/' . $item_id);
  }

  /**
   * Get filename.
   *
   * @param int $item_id
   *   ID of the item.
   *
   * @return string
   *   Filename.
   */
  public function getFilename(int $item_id): string {
    return $this->doRequest('/media/' . $item_id)->filename;
  }

  /**
   * Get item download url.
   *
   * @param int $item_id
   *   ID of the item.
   *
   * @return string
   *   Download url.
   */
  public function getItemDownloadUrl(int $item_id): string {
    return $this->doRequest('/media/' . $item_id . '/download_location')->url;
  }

  /**
   * @return \Drupal\media_skyfish\ConfigService
   */
  public function getConfig(): ConfigService {
    return $this->config;
  }

}
