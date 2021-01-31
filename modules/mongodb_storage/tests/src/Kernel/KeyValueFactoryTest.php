<?php

declare(strict_types = 1);

namespace Drupal\Tests\mongodb_storage\Kernel;

use Drupal\mongodb_storage\KeyValueStore;
use Drupal\mongodb_storage\KeyValueStoreExpirable;
use Drupal\mongodb_storage\Storage;

/**
 * Tests the KeyValueFactory.
 *
 * @coversDefaultClass \Drupal\mongodb_storage\KeyValueFactory
 *
 * @group MongoDB
 */
class KeyValueFactoryTest extends KeyValueTestBase {

  const COLLECTION = 'xyzzy';

  /**
   * Test the collections provided by the expirable Key-Value factory.
   *
   * @throws \Exception
   */
  public function testGetExpirable() {
    /** @var \Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface $factory */
    $factory = $this->container->get(Storage::SERVICE_KVE);
    $store = $factory->get(static::COLLECTION);

    $this->assertInstanceOf(KeyValueStoreExpirable::class, $store, "Store is not an expirable key-value");
    $actual = $store->getCollectionName();
    $this->assertEquals(static::COLLECTION, $actual, 'Collection name matches');
  }

  /**
   * Test the collections provided by the persistent Key-Value factory.
   *
   * @throws \Exception
   */
  public function testGetPersistent() {
    /** @var \Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface $factory */
    $factory = $this->container->get(Storage::SERVICE_KV);
    $store = $factory->get(static::COLLECTION);

    $this->assertInstanceOf(KeyValueStore::class, $store, "Store is a MongoDB key-value");
    $this->assertNotInstanceOf(KeyValueStoreExpirable::class, $store, "Store is not an expirable key-value");
    $actual = $store->getCollectionName();
    $this->assertEquals(static::COLLECTION, $actual, 'Collection name matches');
  }

}
