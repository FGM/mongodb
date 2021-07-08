<?php

declare(strict_types = 1);

namespace Drupal\Tests\mongodb\Kernel;

use Drupal\mongodb\ClientFactory;
use Drupal\mongodb\DatabaseFactory;
use Drupal\mongodb\MongoDb;
use MongoDB\Database;

/**
 * Class DatabaseFactoryTest.
 *
 * @coversDefaultClass \Drupal\mongodb\DatabaseFactory
 *
 * @group MongoDB
 */
class DatabaseFactoryTest extends MongoDbTestBase {

  /**
   * Modules to enable.
   *
   * @var array
   */
  protected static $modules = [MongoDb::MODULE];

  /**
   * The mongodb.client_factory service.
   *
   * @var \Drupal\mongodb\ClientFactory
   */
  protected $clientFactory;

  /**
   * The mongodb.database_factory service.
   *
   * @var \Drupal\mongodb\DatabaseFactory
   */
  protected $databaseFactory;

  /**
   * {@inheritdoc}
   */
  public function setUp(): void {
    parent::setUp();
    $this->clientFactory = new ClientFactory($this->settings);
    $this->databaseFactory = new DatabaseFactory($this->clientFactory, $this->settings);
  }

  /**
   * Test normal case.
   */
  public function testGetHappy() {
    $drupal = $this->databaseFactory->get(static::DB_DEFAULT_ALIAS);
    $this->assertInstanceOf(Database::class, $drupal, 'get() returns a valid database instance.');
  }

  /**
   * Test referencing an alias not present in settings.
   */
  public function testGetSadUnsetAlias() {
    try {
      $this->databaseFactory->get(static::DB_UNSET_ALIAS);
      $this->fail('Should not have returned a value for an unset database alias.');
    }
    catch (\InvalidArgumentException $e) {
      $this->assertTrue(TRUE, 'Throws expected exception for unset database alias.');
    }
    catch (\Exception $e) {
      $this->fail(strtr('Unexpected exception thrown for unset alias: @exception', [
        '@exception' => $e->getMessage(),
      ]));
    }
  }

  /**
   * Test referencing an alias pointing to an ill-formed (empty) database name.
   */
  public function testGetSadAliasForBadDatabase() {
    $database = $this->databaseFactory->get(static::DB_INVALID_ALIAS);
    $this->assertNull($database, 'Selecting an invalid alias returns a null database.');
  }

}
