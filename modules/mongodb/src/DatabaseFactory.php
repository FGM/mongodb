<?php

declare(strict_types = 1);

namespace Drupal\mongodb;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\Core\Site\Settings;

/**
 * Helper class to construct a MongoDB Database with Drupal specific config.
 *
 * @package Drupal\mongodb
 */
class DatabaseFactory {

  /**
   * The Client factory service.
   *
   * @var \Drupal\mongodb\ClientFactory
   */
  protected $clientFactory;

  /**
   * The 'mongodb' database settings array.
   *
   * @var string[][]
   */
  protected $settings;

  /**
   * Constructor.
   *
   * @param \Drupal\mongodb\ClientFactory $clientFactory
   *   The Client factory service.
   * @param \Drupal\Core\Site\Settings $settings
   *   The settings service.
   */
  public function __construct(ClientFactory $clientFactory, Settings $settings) {
    $this->clientFactory = $clientFactory;
    $this->settings = $settings->get('mongodb')['databases'];
  }

  /**
   * Return the MongoDB database matching an alias.
   *
   * @param string $dbAlias
   *   The alias string, like "default".
   *
   * @return \MongoDB\Database|null
   *   The selected database, or NULL if an error occurred.
   */
  public function get($dbAlias) {
    if (!isset($this->settings[$dbAlias])) {

      throw new \InvalidArgumentException((new FormattableMarkup('Nonexistent database alias: @alias', [
        '@alias' => $dbAlias,
      ]))->__toString());
    }
    try {
      list($clientAlias, $database) = $this->settings[$dbAlias];
      $client = $this->clientFactory->get($clientAlias);
      $result = $client->selectDatabase($database);
    }
    // Includes its descendant \MongoDb\Exception\InvalidArgumentException.
    catch (\InvalidArgumentException $e) {
      $result = NULL;
    }

    return $result;
  }

  /**
   * Return the next integer ID in a sequence. For numeric ids in collections.
   *
   * @param string $sequenceId
   *   The name of the sequence, typically a collection name in the current
   *   database.
   * @param int $value
   *   Optional. If given, the result will be at least 1 more that this.
   *
   * @return int
   *   The next id. It will be greater than $value, possibly by more than 1.
   */
  public function nextId($sequenceId = 'sequences', $value = 0) {
    $collection = $this->get('default')
      ->selectCollection('sequences');
    $sequenceSelector = ['_id' => $sequenceId];

    // Force the minimum if given.
    if ($value) {
      $selector = $sequenceSelector + [
        'value' => ['$lt' => $value],
      ];
      $update = [
        '$set' => ['value' => $value],
      ];
      $collection->updateOne($selector, $update);
    }

    // Then increment it.
    $update = [
      '$inc' => ['value' => 1],
    ];
    $options = [
      'upsert' => TRUE,
      'returnDocument' => FindOneAndUpdate::RETURN_DOCUMENT_AFTER,
    ];
    $document = $collection->findOneAndUpdate($sequenceSelector, $update, $options);
    $result = $document->value ?? 1;
    return $result;
  }

}
