<?php

declare(strict_types=1);

namespace Drupal\Tests\mongodb_watchdog\Unit;

use Drupal\mongodb_watchdog\Logger;

class MockLogger extends Logger {

  /**
   * TestLogger mock constructor.
   */
  public function __construct() {
  }

  /**
   * @throws \ReflectionException
   */
  public function enhanceLogEntry(array &$entry, array $backtrace): void {
    parent::enhanceLogEntry($entry, $backtrace);
  }
}
