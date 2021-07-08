<?php

declare(strict_types = 1);

namespace Drupal\Tests\mongodb\Kernel;

use Drupal\Core\Site\Settings;
use Drupal\KernelTests\KernelTestBase;
use Drupal\mongodb\ClientFactory;
use Drupal\mongodb\DatabaseFactory;
use Drupal\mongodb\MongoDb;

/**
 * Class MongoDbTestBase provides basic setUp()/tearDown() for MongoDB.
 *
 * @group MongoDB
 */
abstract class MongoDbTestBase extends KernelTestBase {
  const DEFAULT_URI = 'mongodb://localhost:27017';
  const CLIENT_BAD_ALIAS = 'bad';
  const CLIENT_TEST_ALIAS = 'test';

  const DB_BAD_CLIENT_ALIAS = 'bad';
  const DB_INVALID_ALIAS = 'invalid';
  const DB_DEFAULT_ALIAS = 'default';
  const DB_UNSET_ALIAS = 'unset';

  /**
   * Modules to enable.
   *
   * @var array
   */
  protected static $modules = [MongoDb::MODULE];

  /**
   * A test-specific instance of Settings.
   *
   * @var \Drupal\Core\Site\Settings
   */
  protected $settings;

  /**
   * The MongoDB URI for a test server.
   *
   * @var string
   */
  protected $uri;

  /**
   * Obtain the name of a per-test database.
   *
   * @param string $postfix
   *   The way for the caller to differentiate this database from others.
   *
   * @return string
   *   The name of the per-test database, like 'simpletest1234_foo'.
   */
  public function getTestDatabaseName($postfix) {
    return $this->getDatabasePrefix() . '_' . $postfix;
  }

  /**
   * Prepare the Settings from a base set of MongoDB settings.
   *
   * @return array
   *   A settings array only containing MongoDB-related settings.
   */
  protected function getSettingsArray() : array {
    return [
      'clients' => [
        static::CLIENT_BAD_ALIAS => [
          'uri' => 'mongodb://localhost:80',
          'uriOptions' => [],
          'driverOptions' => [],
        ],
        static::CLIENT_TEST_ALIAS => [
          'uri' => $this->uri,
          'uriOptions' => [],
          'driverOptions' => [],
        ],
      ],
      'databases' => [
        static::DB_DEFAULT_ALIAS => [static::CLIENT_TEST_ALIAS, $this->getDatabasePrefix()],
        static::DB_INVALID_ALIAS => [static::CLIENT_TEST_ALIAS, ''],
        static::DB_BAD_CLIENT_ALIAS => [static::CLIENT_BAD_ALIAS, $this->getDatabasePrefix()],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function setUp(): void {
    parent::setUp();
    // $_ENV if it comes from phpunit.xml <env>
    // $_SERVER if it comes from the phpunit command line environment.
    $this->uri = $_ENV['MONGODB_URI']
      ?? $_SERVER['MONGODB_URI']
      ?? static::DEFAULT_URI;

    $this->settings = new Settings([MongoDb::MODULE => $this->getSettingsArray()]);
  }

  /**
   * {@inheritdoc}
   */
  public function tearDown(): void {
    $clientFactory = new ClientFactory($this->settings);
    $databaseFactory = new DatabaseFactory($clientFactory, $this->settings);
    $databaseFactory->get(static::DB_DEFAULT_ALIAS)
      ->drop();

    parent::tearDown();
  }

}
