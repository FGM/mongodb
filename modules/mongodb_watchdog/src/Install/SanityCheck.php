<?php

declare(strict_types = 1);

namespace Drupal\mongodb_watchdog\Install;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\mongodb\DatabaseFactory;
use Drupal\mongodb_watchdog\Logger;

/**
 * Class SanityCheck provides some reasonableness checks for MongoDB contents.
 *
 * @see \Drupal\mongodb_watchdog\Command\SanityCheckCommand
 * @see \Drupal\mongodb_watchdog\Commands\MongoDbWatchdogCommands::sanityCheck()
 */
class SanityCheck {

  /**
   * The per-collection-size statistics buckets.
   *
   * @var array
   */
  protected $buckets;

  /**
   * The module configuration.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $config;

  /**
   * The mongodb.database_factory service.
   *
   * @var \Drupal\mongodb\DatabaseFactory
   */
  protected $dbFactory;

  /**
   * The bucket size values.
   *
   * @var array
   */
  protected $items;

  /**
   * SanityCheck constructor.
   *
   * @param \Drupal\mongodb\DatabaseFactory $dbFactory
   *   The mongodb.database_factory service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config.factory service.
   */
  public function __construct(
    DatabaseFactory $dbFactory,
    ConfigFactoryInterface $configFactory
  ) {
    $this->dbFactory = $dbFactory;

    $this->config = $configFactory->get(Logger::CONFIG_NAME);
    $this->items = $this->config->get(Logger::CONFIG_ITEMS);
  }

  /**
   * Build a list of the number of entries per collection in the default DB.
   */
  public function buildCollectionstats(): array {
    /** @var \Drupal\mongodb\DatabaseFactory $databaseFactory */
    $database = $this->dbFactory->get(Logger::DB_LOGGER);
    $this->initBucketsList();

    $collections = $database->listCollections();
    foreach ($collections as $collectionInfo) {
      $name = $collectionInfo->getName();
      $collection = $database->selectCollection($name);
      $count = $collection->countDocuments();
      if (preg_match('/' . Logger::EVENT_COLLECTIONS_PATTERN . '/', $name)) {
        $this->store($count);
      }
    }

    return $this->buckets;
  }

  /**
   * Prepare a table of bucket to hold the statistics.
   */
  protected function initBucketsList(): void {
    $barCount = 10;
    $barWidth = $this->items / $barCount;
    $buckets = [
      0 => 0,
      1 => 0,
      $this->items - 1 => 0,
      $this->items => 0,
    ];

    // Values 0, 1 and the value of $items are reserved.
    for ($i = 1; $i < $barCount; $i++) {
      $buckets[$i * $barWidth] = 0;
    }
    ksort($buckets);
    $this->buckets = $buckets;
  }

  /**
   * Store a collection document count in its statistics bucket.
   *
   * @param int $value
   *   The value to store.
   */
  protected function store(int $value): void {
    if ($value <= 1 || $value >= $this->items - 1) {
      $this->buckets[$value]++;
      return;
    }
    $keys = array_slice(array_keys($this->buckets), 1, -1);

    foreach ($keys as $key) {
      if ($value < $key) {
        $this->buckets[$key]++;
        return;
      }
    }
  }

}
