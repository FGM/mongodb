<?php

declare(strict_types = 1);

namespace Drupal\Tests\mongodb_watchdog\Unit;

use Drupal\mongodb_watchdog\Controller\ControllerBase;
use Drupal\Tests\UnitTestCase;

/**
 * Test the ControllerBase mechanisms.
 *
 * @coversDefaultClass \Drupal\mongodb_watchdog\Controller\ControllerBase
 *
 * @group mongodb
 */
class ControllerBaseTest extends UnitTestCase {

  const ITEMS_PER_PAGE = 50;

  /**
   * Test page generation for various data set shapes.
   *
   * @covers ::getPage
   *
   * @dataProvider pageGenerationData
   */
  public function testPageGeneration(int $requestedPage, float $count, int $expected) {
    $actual = ControllerBase::getPage($count, $requestedPage, static::ITEMS_PER_PAGE);
    $this->assertEquals($expected, $actual);
  }

  /**
   * Data provider for testPageGeneration().
   *
   * @see \Drupal\Tests\mongodb_watchdog\Unit\ControllerBaseTest::testPageGeneration()
   *
   * Coding standards are ignored for the data list for the sake of readability.
   */
  public function pageGenerationData() {
    // One partial available page.
    $one = static::ITEMS_PER_PAGE;
    // Part of one page.
    $partial = floor($one * 0.6);
    // More than one available page.
    $oneplus = $one + $partial;
    // Exactly two pages.
    $two = $one * 2;
    $twoplus = $two + $partial;

    $expectations = [
      // @codingStandardsIgnoreStart
      // page, count, result
      [-1,    0,          0],
      [-1,    $partial,   0],
      [-1,    $one,       0],
      [-1,    $oneplus,   0],
      [-1,    $two,       0],

      [ 0,    0,          0],
      [ 0,    $partial,   0],
      [ 0,    $one,       0],
      [ 0,    $oneplus,   0],
      [ 0,    $two,       0],

      [ 1,    0,          0],
      [ 1,    $partial,   0],
      [ 1,    $one,       0],
      [ 1,    $oneplus,   1],
      [ 1,    $two,       1],

      [ 2,    0,          0],
      [ 2,    $partial,   0],
      [ 2,    $one,       0],
      [ 2,    $oneplus,   1],
      [ 2,    $two,       1],
      [ 2,    $twoplus,   2],
      // @codingStandardsIgnoreEnd
    ];
    return $expectations;
  }

}
