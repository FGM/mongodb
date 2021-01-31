<?php

declare(strict_types = 1);

namespace Drupal\mongodb_watchdog\Commands;

use Drupal\mongodb_watchdog\Install\SanityCheck;
use Drush\Commands\DrushCommands;

/**
 * Drush 9 commands service for the mongodb_watchdog module.
 */
class MongoDbWatchdogCommands extends DrushCommands {

  /**
   * The mongodb.watchdog.sanity_check service.
   *
   * @var \Drupal\mongodb_watchdog\Install\SanityCheck
   */
  protected $sanityCheck;

  /**
   * MongodbWatchdogCommands constructor.
   *
   * @param \Drupal\mongodb_watchdog\Install\SanityCheck $sanityCheck
   *   The mongodb.watchdog.sanity_check service.
   */
  public function __construct(SanityCheck $sanityCheck) {
    $this->sanityCheck = $sanityCheck;
  }

  /**
   * Run a sanity check on the contents of the logger database in MongoDB.
   *
   * @param array $options
   *   A Drush-magic parameter enabling Drush to choose the output format.
   *
   * @return array
   *   An array of collection by document count range. A high number of single
   *   document collections is a hint of a problem with the application code
   *   using the logger subsystem.
   *
   * @usage mongodb:watchdog:sanitycheck
   *   Report on the site of the event collections, per size bucket.
   *
   * The "unused" $options allows Drush to know the command should support the
   * --format option, with the chosen default.
   *
   * @command mongodb:watchdog:sanitycheck
   * @aliases mdbwsc,mowd-sc
   */
  public function sanityCheck(array $options = ['format' => 'yaml']): array {
    return $this->sanityCheck->buildCollectionstats();
  }

}
