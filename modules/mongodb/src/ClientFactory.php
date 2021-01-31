<?php

declare(strict_types = 1);

namespace Drupal\mongodb;

use Drupal\Core\Site\Settings;
use MongoDB\Client;

/**
 * Helper class to construct a MongoDB client with Drupal specific config.
 */
class ClientFactory {

  /**
   * The 'mongodb' client settings.
   *
   * @var string[][]
   */
  protected $settings;

  /**
   * A hash of connections per alias.
   *
   * @var \MongoDB\Client[]
   */
  protected $clients;

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Site\Settings $settings
   *   The system settings.
   */
  public function __construct(Settings $settings) {
    $this->settings = $settings->get(MongoDb::MODULE)['clients'];
  }

  /**
   * Return a Client instance for a given alias.
   *
   * @param string $alias
   *   The alias defined in settings for a Client.
   *
   * @return \MongoDB\Client
   *   A Client instance for the chosen server.
   */
  public function get($alias) {
    if (!isset($this->clients[$alias]) || !$this->clients[$alias] instanceof Client) {
      $info = $this->settings[$alias] ?? [];
      $info += [
        'uri' => 'mongodb://localhost:27017',
        'uriOptions' => [],
        'driverOptions' => [],
      ];

      // Don't use ...$info: keys can be out of order.
      $this->clients[$alias] = new Client($info['uri'], $info['uriOptions'], $info['driverOptions']);
    }

    return $this->clients[$alias];
  }

}
