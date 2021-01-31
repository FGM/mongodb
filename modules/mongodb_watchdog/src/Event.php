<?php

declare(strict_types = 1);

namespace Drupal\mongodb_watchdog;

use Drupal\Core\Logger\RfcLogLevel;
use MongoDB\BSON\Unserializable;

/**
 * Class Event is a value object for a logged event.
 *
 * @package Drupal\mongodb_watchdog
 *
 * Since this is essentially a value object, naming is constrained by the
 * property names in MongoDB, so ignore variable naming rules for fields.
 *
 * @SuppressWarnings(PHPMD.CamelCasePropertyName)
 * @SuppressWarnings(Variable)
 */
class Event implements Unserializable {
  const KEYS = [
    '_id',
    'hostname',
    'link',
    'location',
    'message',
    'referrer',
    'severity',
    'timestamp',
    'type',
    'uid',
    'variables',
    'requestTracking_id',
    'requestTracking_sequence',
  ];

  // @codingStandardsIgnoreStart
  /**
   * The string representation of a MongoId.
   *
   * @var int
   */
  public $_id;
  // @codingStandardsIgnoreEnd

  /**
   * User id. Use uid() instead for type safety.
   *
   * @var int
   */
  public $uid;

  /**
   * Event type, often a module name.
   *
   * @var string
   */
  public $type;

  /**
   * Event template.
   *
   * @var string
   */
  public $message;

  // @codingStandardsIgnoreStart
  /**
   * The identifier of the request during which this event occurred.
   *
   * Coding standards are suspended for requestTracking_id which is required by
   * the MongoDB property.
   *
   * @var string
   */
  public $requestTracking_id;
  // @codingStandardsIgnoreEnd

  // @codingStandardsIgnoreStart
  /**
   * The sequence number of the event during the request when it happened.
   *
   * Coding standards are suspended for requestTracking_sequence which is
   * required by the MongoDB property.
   *
   * @var int
   */
  public $requestTracking_sequence = 0;
  // @codingStandardsIgnoreEnd

  /**
   * The template parameters.
   *
   * @var array
   */
  public $variables = [];

  /**
   * A RFC5424 severity level.
   *
   * @var int
   */
  public $severity = RfcLogLevel::DEBUG;

  /**
   * A link provided by the event emitter. Optional.
   *
   * @var string
   */
  public $link;

  /**
   * The absolute URL for the path on which event was logged. Use location().
   *
   * @var string
   */
  public $location;

  /**
   * A HTTP referrer for the path on which the event was logged. Optional.
   *
   * @var string
   */
  public $referrer;

  /**
   * The server host.
   *
   * @var string
   */
  public $hostname;

  /**
   * The timestamp at which the event was logged.
   *
   * @var int
   */
  public $timestamp = 0;

  /**
   * Constructor.
   *
   * @param array $event
   *   The event in array form.
   */
  public function __construct(array $event) {
    $this->bsonUnserialize($event);
  }

  /**
   * {@inheritdoc}
   */
  public function bsonUnserialize(array $data): void {
    foreach (static::KEYS as $key) {
      if (isset($data[$key])) {
        $this->{$key} = $data[$key];
      }
    }
  }

  /**
   * Type-safe getter for location.
   *
   * @return string
   *   The location property, always as a string, never NULL.
   */
  public function location(): string {
    return (string) $this->location ?? '';
  }

  /**
   * Type-safe getter for uid.
   *
   * @return int
   *   The uid property, always as an int, never NULL.
   */
  public function uid(): int {
    return (int) $this->uid;
  }

}
