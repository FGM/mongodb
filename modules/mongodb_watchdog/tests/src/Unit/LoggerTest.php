<?php

namespace Drupal\Tests\mongodb_watchdog\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Test the ControllerBase mechanisms.
 *
 * @coversDefaultClass \Drupal\mongodb_watchdog\Logger
 *
 * @group mongodb
 */
class LoggerTest extends TestCase {

  public function setUp(): void {
    require_once __DIR__ . "/../../modules/mongodb_watchdog_test/mongodb_watchdog_test.module";
  }

  /**
   * @link https://www.drupal.org/project/mongodb/issues/3219325
   * @covers ::enhanceLogEntry
   *
   * @throws \ReflectionException
   */
  public function testEnhanceLogEntry() {
    $backtrace = mongodb_watchdog_test_3219325();
    $logger = new MockLogger();
    $entry = [];
    try {
      $logger->enhanceLogEntry($entry, $backtrace);
    }
    catch (\ReflectionException $e) {
      $this->fail($e->getMessage());
    }
  }
}
