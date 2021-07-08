<?php

declare(strict_types = 1);

namespace Drupal\Tests\mongodb_watchdog\Kernel;

use Drupal\Core\Logger\RfcLogLevel;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\mongodb\MongoDb;
use Drupal\mongodb_watchdog\Logger;
use Drupal\Tests\mongodb\Kernel\MongoDbTestBase;

/**
 * Class LoggerTest tests the logging mechanism itself.
 *
 * @coversDefaultClass \Drupal\mongodb_watchdog\Logger
 *
 * @group MongoDB
 */
class LoggerTest extends MongoDbTestBase {
  use StringTranslationTrait;

  /**
   * The event templates collection.
   *
   * @var \MongoDB\Collection
   */
  protected $collection;

  /**
   * These modules need to be enabled.
   *
   * @var array
   */
  protected static $modules = [
    'system',
    MongoDb::MODULE,
    Logger::MODULE,
  ];

  /**
   * {@inheritdoc}
   */
  public function setUp(): void {
    parent::setUp();
    $this->installConfig(Logger::MODULE);
  }

  /**
   * {@inheritdoc}
   */
  protected function getSettingsArray(): array {
    $settings = parent::getSettingsArray();
    $settings['databases'][Logger::DB_LOGGER] = [
      static::CLIENT_TEST_ALIAS,
      $this->getDatabasePrefix(),
    ];

    return $settings;
  }

  /**
   * Assert that a given entry is present in the watchdog.
   *
   * @param string $message
   *   The message is present in the collection.
   */
  public function assertEntry($message) {
    $logged = $this->find($message);
    $this->assertNotNull($logged,
      (string) $this->t('Event %message is logged', ['%message' => $message]));
    $this->assertTrue(isset($logged['message']) && $logged['message'] == $message,
      (string) $this->t('Logged message is unchanged'));
  }

  /**
   * Assert that a given entry is not present in the watchdog.
   *
   * @param string $message
   *   The message which must not be present in the collection.
   */
  public function assertNoEntry($message) {
    $logged = $this->find($message);
    $this->assertNull($logged,
      (string) $this->t('Event %message is not logged', ['%message' => $message]));
  }

  /**
   * Replaces PSR-3 braces by angle brackets.
   *
   * Braces in log($l, $message, $c) will be interpreted as PSR-3 placeholders.
   * As such they need to be avoid when inserted randomly.
   *
   * @param string $message
   *   The raw message.
   *
   * @return string
   *   The replacement message.
   *
   * @see \Drupal\Core\Logger\LogMessageParserInterface::parseMessagePlaceholders()
   */
  public static function debrace(string $message): string {
    return str_replace(['{', '}'], ['<', '>'], $message);
  }

  /**
   * Simplified query to look for a logged message.
   *
   * @param string $message
   *   The message to look for.
   *
   * @return array|null
   *   The document containing the message, if any ; NULL otherwise.
   */
  protected function find($message) {
    $ret = $this->collection->findOne(['message' => $message]);
    return $ret;
  }

  /**
   * Test the default and non-default mongodb_watchdog insertion behaviours.
   *
   * Make sure the module applies the watchdog_limit variable,
   *
   * @covers ::log
   */
  public function testWatchdogLimit() {
    $config = $this->config(Logger::CONFIG_NAME);
    $limit = $config->get(Logger::CONFIG_LIMIT);
    $this->assertEquals(RfcLogLevel::DEBUG, $limit,
      (string) $this->t('%name defaults to @level', [
        '%name' => Logger::CONFIG_LIMIT,
        '@level' => RfcLogLevel::DEBUG,
      ]));

    $logger = $this->container->get(Logger::SERVICE_LOGGER);
    $database = $this->container->get(MongoDb::SERVICE_DB_FACTORY)
      ->get(Logger::DB_LOGGER);
    $this->collection = $database->selectCollection(Logger::TEMPLATE_COLLECTION);
    $this->collection->drop();

    $message = static::debrace($this->randomString(32));
    $logger->log($limit, $message);
    $this->assertEntry($message);

    // Now request a higher level: unimportant events should be ignored. For
    // this to work, ensure limit is not the maximum level already.
    $logger->setLimit(RfcLogLevel::INFO);
    $this->collection->drop();

    $message = $this->randomMachineName(32);
    $logger->debug($message);
    $this->assertNoEntry($message);

    // ... but events at the limit or more important should be logged.
    $message = $this->randomMachineName(32);
    $logger->notice($message);
    $this->assertEntry($message);

    $message = $this->randomMachineName(32);
    $logger->error($message);
    $this->assertEntry($message);
  }

}
