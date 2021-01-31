<?php

declare(strict_types = 1);

namespace Drupal\Tests\mongodb\Kernel;

use Drupal\mongodb\MongoDb;
use MongoDB\Collection;

/**
 * Tests the MongoDB main class.
 *
 * @coversDefaultClass \Drupal\mongodb\MongoDb
 *
 * @group MongoDB
 */
class MongoDbTest extends MongoDbTestBase {

  /**
   * @covers ::libraryApiVersion
   */
  public function testLibraryVersion() {
    $actual = MongoDb::libraryApiVersion();
    $this->assertRegExp('/[\d]\.[\d]+\.[\d]+/', $actual,
      'API version matches expected format.');
    list(, $minor,) = sscanf($actual, "%d.%d.%d");
    $hasWatch = method_exists(Collection::class, 'watch');
    $hasCountDocuments = method_exists(Collection::class, 'countDocuments');
    switch ($minor) {
      case 2:
        $this->assertFalse($hasWatch);
        $this->assertFalse($hasCountDocuments);
        break;

      case 3:
        $this->assertTrue($hasWatch);
        $this->assertFalse($hasCountDocuments);
        break;

      case 4:
        $this->assertTrue($hasWatch);
        $this->assertTrue($hasCountDocuments);
        break;

      default:
        $this->fail("Unexpected API version: $actual");
    }
  }

  /**
   * @covers ::countCollection
   */
  public function testCountCollection() {
    /** @var \Drupal\mongodb\DatabaseFactory $dbFactory */
    $dbFactory = $this->container->get(MongoDb::SERVICE_DB_FACTORY);
    $database = $dbFactory->get(MongoDb::DB_DEFAULT);
    $collectionName = $this->getDatabasePrefix() . $this->randomMachineName();
    $collection = $database->selectCollection($collectionName);
    $collection->drop();

    $expected = mt_rand(0, 100);
    $docs = [];
    for ($i = 0; $i < $expected; $i++) {
      $docs[] = [
        "index" => $i,
      ];
    }
    $collection->insertMany($docs);
    $actual = MongoDb::countCollection($collection);
    $this->assertEquals($expected, $actual,
      "countCollection finds the correct number of documents");
  }

}
