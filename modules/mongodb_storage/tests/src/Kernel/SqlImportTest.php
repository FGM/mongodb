<?php

declare(strict_types = 1);

namespace Drupal\Tests\mongodb_storage\Kernel;

use Drupal\mongodb\MongoDb;
use Drupal\mongodb_storage\Install\SqlImport;
use Drupal\mongodb_storage\KeyValueExpirableFactory;
use Drupal\mongodb_storage\KeyValueFactory;
use Drupal\mongodb_storage\Storage;

/**
 * Tests the import for the commands.mongodb.storage.import_keyvalue command.
 *
 * @coversDefaultClass \Drupal\mongodb_storage\Install\SqlImport
 *
 * @group MongoDB
 */
class SqlImportTest extends KeyValueTestBase {

  const IMPORT_OUTPUT = SqlImport::KVP_TABLE . PHP_EOL
  . SqlImport::KVE_TABLE . PHP_EOL;

  /**
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = [
    'system',
    MongoDb::MODULE,
    Storage::MODULE,
  ];

  /**
   * The database service.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The mongodb.storage.sql_import service.
   *
   * @var \Drupal\mongodb_storage\Install\SqlImport
   */
  protected $sqlImport;

  /**
   * Install the database keyvalue tables for import.
   */
  public function setUp(): void {
    parent::setUp();
    $this->installSchema('system', [
      SqlImport::KVP_TABLE,
      SqlImport::KVE_TABLE,
    ]);
    $this->database = $this->container->get('database');
    $this->sqlImport = $this->container->get(Storage::SERVICE_SQL_IMPORT);
  }

  /**
   * Test helper: count rows in a table.
   *
   * @param string $name
   *   The name of the table.
   *
   * @return int
   *   The number of rows in the table.
   */
  protected function countTable(string $name): int {
    $count = (int) $this->database->select($name)
      ->countQuery()
      ->execute()
      ->fetchField();
    return $count;
  }

  /**
   * Test helper: list the KV(E|P) collections.
   *
   * @return array
   *   The sorted array of the (unprefixed) KV collections names.
   *
   * @throws \Exception
   */
  protected function getKvCollectionNames(string $prefix): array {
    $cursor = $this->container
      ->get(MongoDb::SERVICE_DB_FACTORY)
      ->get(KeyValueFactory::DB_KEYVALUE)
      ->listCollections();

    $result = [];
    $len = strlen($prefix);
    foreach ($cursor as $collection) {
      if (strpos($name = $collection->getName(), $prefix) === 0) {
        $result[] = substr($name, $len);
      }
    }

    sort($result);
    return $result;
  }

  /**
   * @covers ::__construct
   */
  public function testImportService() {
    $this->assertInstanceOf(SqlImport::class, $this->sqlImport,
      "SQL import service is available");
    $this->assertTrue(method_exists($this->sqlImport, 'import'));
  }

  /**
   * @covers ::import
   */
  public function testImport() {
    $this->expectOutputString(self::IMPORT_OUTPUT);
    $this->sqlImport->import();
  }

  /**
   * Data provider for testImportActual.
   *
   * @return array
   *   The test data.
   */
  public function importProvider() : array {
    return [
      [
        SqlImport::KVP_TABLE,
        Storage::SERVICE_KV,
        KeyValueFactory::COLLECTION_PREFIX,
      ],
      [
        SqlImport::KVE_TABLE,
        Storage::SERVICE_KVE,
        KeyValueExpirableFactory::COLLECTION_PREFIX,
      ],
    ];
  }

  /**
   * @covers ::getCollections
   * @covers ::importPersistent
   * @covers ::importExpirable
   *
   * @dataProvider importProvider
   */
  public function testImportActual(string $table, string $service, string $prefix) {
    if (!function_exists(system_schema::class)) {
      \module_load_install('system');
    }
    $columns = array_keys(system_schema()[$table]['fields']);

    $actualPreDbCount = $this->countTable($table);
    $this->assertEquals(0, $actualPreDbCount);

    $actualPreMgCount = count($this->getKvCollectionNames($prefix));
    $this->assertEquals(0, $actualPreMgCount);

    // Avoid inserting nothing, or too much data.
    $rowCount = mt_rand(1, 100);
    $rows = [];
    $collection = $this->randomMachineName();
    for ($i = 0; $i < $rowCount; $i++) {
      // Have a good chance to keep more than one value per collection.
      if (mt_rand(0, 10) >= 8) {
        $collection = $this->randomMachineName();
      }
      $name = $this->randomMachineName();
      // DatabaseStorage stores values as serialized PHP.
      $value = serialize($this->randomString(1024));
      $row = [$collection, $name, $value];
      if (count($columns) === 4) {
        // Ensure test will have time to run before MongoDB expires data.
        $row[] = time() + 180;
      }
      $rows[] = $row;
    }
    $expectedCollections = [];
    foreach ($rows as $row) {
      $expectedCollections[$row[0]][$row[1]] = unserialize($row[2]);
    }
    ksort($expectedCollections);
    foreach ($expectedCollections as $name => &$values) {
      ksort($values);
    }

    $insert = $this->database->insert($table)
      ->fields($columns);
    foreach ($rows as $row) {
      $insert->values($row);
    }
    $insert->execute();

    $this->expectOutputString(self::IMPORT_OUTPUT);
    $this->sqlImport->import();

    $keyValue = $this->container->get($service);
    $mongoCollections = $this->getKvCollectionNames($prefix);
    $this->assertEquals(array_keys($expectedCollections), $mongoCollections, "Collection names match");
    foreach ($expectedCollections as $collectionName => $expected) {
      $all = $keyValue->get($collectionName)->getAll();
      ksort($all);
      $this->assertEquals($expected, $all);
    }
  }

}
