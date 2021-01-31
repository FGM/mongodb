<?php
declare(strict_types = 1);

namespace Drupal\mongodb\Queue;

use Drupal\Core\Site\Settings;
use MongoDB\Database;

/**
 * Defines the queue factory for the MongoDB backend.
 */
class QueueMongodbFactory {

  /**
   * The queue storage.
   *
   * @var \MongoDB\Database
   */
  protected $database;

  /**
   * The settings array.
   *
   * @var \Drupal\Core\Site\Settings
   */
  protected $settings;

  /**
   * Constructs this factory object.
   *
   * @param \MongoDB\Database $database
   *   The database object.
   * @param \Drupal\Core\Site\Settings $settings
   *   The system settings.
   */
  public function __construct(Database $database, Settings $settings) {
    $this->database = $database;
    $this->settings = $settings;
  }

  /**
   * Constructs a new queue object for a given name.
   *
   * @param string $name
   *   The name of the collection holding key and value pairs.
   *
   * @return \Drupal\Core\Queue\DatabaseQueue
   *   A key/value store implementation for the given $collection.
   */
  public function get($name) {
    $settings = $this->settings->get('mongodb_queue_' . $name);
    return new MongodbQueue($name, $settings, $this->database);
  }

}
