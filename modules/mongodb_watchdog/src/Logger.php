<?php

declare(strict_types=1);

namespace Drupal\mongodb_watchdog;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Render\MarkupInterface;
use Drupal\Component\Utility\Xss;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LogMessageParserInterface;
use Drupal\Core\Logger\RfcLogLevel;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use MongoDB\Collection;
use MongoDB\Database;
use MongoDB\Driver\Cursor;
use MongoDB\Driver\Exception\InvalidArgumentException;
use MongoDB\Driver\Exception\RuntimeException;
use MongoDB\Driver\WriteConcern;
use MongoDB\Model\CollectionInfoIterator;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Class Logger is a PSR/3 Logger using a MongoDB data store.
 *
 * @package Drupal\mongodb_watchdog
 */
class Logger extends AbstractLogger {

  use StringTranslationTrait;

  // Configuration-related constants.
  // The configuration item.
  const CONFIG_NAME = 'mongodb_watchdog.settings';

  // The individual configuration keys.
  const CONFIG_ITEMS = 'items';

  const CONFIG_REQUESTS = 'requests';

  const CONFIG_LIMIT = 'limit';

  const CONFIG_ITEMS_PER_PAGE = 'items_per_page';

  const CONFIG_REQUEST_TRACKING = 'request_tracking';

  // The logger database alias.
  const DB_LOGGER = 'logger';

  // The default channel exposed when using the raw PSR-3 contract.
  const DEFAULT_CHANNEL = 'psr-3';

  const MODULE = 'mongodb_watchdog';

  // The service for the specific PSR-3 logger for MongoDB.
  const SERVICE_LOGGER = 'mongodb.logger';

  // The service for the Drupal LoggerChannel for this module, logging to all
  // active loggers.
  const SERVICE_CHANNEL = 'logger.channel.mongodb_watchdog';

  // The service for hook_requirements().
  const SERVICE_REQUIREMENTS = 'mongodb.watchdog_requirements';

  const SERVICE_SANITY_CHECK = 'mongodb.watchdog.sanity_check';

  const TRACKER_COLLECTION = 'watchdog_tracker';

  const TEMPLATE_COLLECTION = 'watchdog';

  const EVENT_COLLECTION_PREFIX = 'watchdog_event_';

  const EVENT_COLLECTIONS_PATTERN = '^watchdog_event_[[:xdigit:]]{32}$';

  const LEGACY_TYPE_MAP = [
    'typeMap' => [
      'array' => 'array',
      'document' => 'array',
      'root' => 'array',
    ],
  ];

  /**
   * Map of PSR3 log constants to RFC 5424 log constants.
   *
   * @var array
   *
   * @see \Drupal\Core\Logger\LoggerChannel
   * @see \Drupal\mongodb_watchdog\Logger::log()
   */
  protected $rfc5424levels = [
    LogLevel::EMERGENCY => RfcLogLevel::EMERGENCY,
    LogLevel::ALERT => RfcLogLevel::ALERT,
    LogLevel::CRITICAL => RfcLogLevel::CRITICAL,
    LogLevel::ERROR => RfcLogLevel::ERROR,
    LogLevel::WARNING => RfcLogLevel::WARNING,
    LogLevel::NOTICE => RfcLogLevel::NOTICE,
    LogLevel::INFO => RfcLogLevel::INFO,
    LogLevel::DEBUG => RfcLogLevel::DEBUG,
  ];

  /**
   * The logger storage.
   *
   * @var \MongoDB\Database
   */
  protected $database;

  /**
   * The limit for the capped event collections.
   *
   * @var int
   */
  protected $items;

  /**
   * The minimum logging level.
   *
   * @var int
   *
   * @see https://drupal.org/node/1355808
   */
  protected $limit = RfcLogLevel::DEBUG;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * The message's placeholders parser.
   *
   * @var \Drupal\Core\Logger\LogMessageParserInterface
   */
  protected $parser;

  /**
   * The "requests" setting.
   *
   * @var int
   */
  protected $requests;

  /**
   * The request_stack service.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * Is request tracking enabled ?
   *
   * @var bool
   */
  protected $requestTracking;

  /**
   * A sequence number for log events during a request.
   *
   * @var int
   */
  protected $sequence = 0;

  /**
   * An array of templates already used in this request.
   *
   * Used only with request tracking enabled.
   *
   * @var string[]
   */
  protected $templates = [];

  /**
   * The datetime.time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected $time;

  /**
   * Logger constructor.
   *
   * @param \MongoDB\Database $database
   *   The database object.
   * @param \Drupal\Core\Logger\LogMessageParserInterface $parser
   *   The parser to use when extracting message variables.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The core config_factory service.
   * @param \Symfony\Component\HttpFoundation\RequestStack $stack
   *   The core request_stack service.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The datetime.time service.
   */
  public function __construct(
    Database $database,
    LogMessageParserInterface $parser,
    ConfigFactoryInterface $configFactory,
    RequestStack $stack,
    MessengerInterface $messenger,
    TimeInterface $time
  ) {
    $this->database = $database;
    $this->messenger = $messenger;
    $this->parser = $parser;
    $this->requestStack = $stack;
    $this->time = $time;

    $config = $configFactory->get(static::CONFIG_NAME);
    // During install, a logger will be invoked 3 times, the first 2 without any
    // configuration information, so hard-coded defaults are needed on all
    // config keys.
    $this->setLimit($config->get(static::CONFIG_LIMIT) ?? RfcLogLevel::DEBUG);
    // Do NOT use 1E4 / 1E5: these are doubles, but config is typed to integers.
    $this->items = $config->get(static::CONFIG_ITEMS) ?? 10000;
    $this->requests = $config->get(static::CONFIG_REQUESTS) ?? 100000;
    $this->requestTracking = $config->get(static::CONFIG_REQUEST_TRACKING) ?? FALSE;
  }

  /**
   * Fill in the log_entry function, file, and line.
   *
   * @param array $entry
   *   An event information to be logger.
   * @param array $backtrace
   *   A call stack.
   *
   * @throws \ReflectionException
   */
  protected function enhanceLogEntry(array &$entry, array $backtrace): void {
    // Create list of functions to ignore in backtrace.
    static $ignored = [
      'call_user_func_array' => 1,
      '_drupal_log_error' => 1,
      '_drupal_error_handler' => 1,
      '_drupal_error_handler_real' => 1,
      'Drupal\mongodb_watchdog\Logger::log' => 1,
      'Drupal\Core\Logger\LoggerChannel::log' => 1,
      'Drupal\Core\Logger\LoggerChannel::alert' => 1,
      'Drupal\Core\Logger\LoggerChannel::critical' => 1,
      'Drupal\Core\Logger\LoggerChannel::debug' => 1,
      'Drupal\Core\Logger\LoggerChannel::emergency' => 1,
      'Drupal\Core\Logger\LoggerChannel::error' => 1,
      'Drupal\Core\Logger\LoggerChannel::info' => 1,
      'Drupal\Core\Logger\LoggerChannel::notice' => 1,
      'Drupal\Core\Logger\LoggerChannel::warning' => 1,
    ];

    foreach ($backtrace as $bt) {
      if (isset($bt['function'])) {
        $function = empty($bt['class']) ? $bt['function'] : $bt['class'] . '::' . $bt['function'];
        if (empty($ignored[$function])) {
          $entry['%function'] = $function;
          /* Some part of the stack, like the line or file info, may be missing.
           *
           * @see http://goo.gl/8s75df
           *
           * No need to fetch the line using reflection: it would be redundant
           * with the name of the function.
           */
          $entry['%line'] = isset($bt['line']) ? $bt['line'] : NULL;
          if (empty($bt['file'])) {
            $reflectionObj = empty($bt['class'])
              ? new \ReflectionFunction($function)
              : new \ReflectionMethod($function);
            $bt['file'] = $reflectionObj->getFileName();
          }

          $entry['%file'] = $bt['file'];
          break;
        }
        elseif ($bt['function'] == '_drupal_exception_handler') {
          $e = $bt['args'][0];
          $this->enhanceLogEntry($entry, $e->getTrace());
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   *
   * @see https://httpd.apache.org/docs/2.4/en/mod/mod_unique_id.html
   */
  public function log($level, $template, array $context = []): void {
    // PSR-3 LoggerInterface documents level as "mixed", while the RFC itself
    // in §1.1 implies implementations may know about non-standard levels. In
    // the case of Drupal implementations, this includes the 8 RFC5424 levels.
    if (is_string($level)) {
      $level = $this->rfc5424levels[$level];
    }

    if ($level > $this->limit) {
      return;
    }

    // Convert PSR3-style messages to SafeMarkup::format() style, so they can be
    // translated at runtime too.
    $placeholders = $this->parser->parseMessagePlaceholders($template,
      $context);

    // If code location information is all present, as for errors/exceptions,
    // then use it to build the message template id.
    $type = $context['channel'] ?? static::DEFAULT_CHANNEL;
    $location = [
      '%type' => 1,
      '@message' => 1,
      '%function' => 1,
      '%file' => 1,
      '%line' => 1,
    ];
    if (!empty(array_diff_key($location, $placeholders))) {
      $this->enhanceLogEntry($placeholders,
        debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10));
    }
    $file = $placeholders['%file'];
    $line = $placeholders['%line'];
    $function = $placeholders['%function'];
    $key = implode(":", [$type, $level, $file, $line, $function]);
    $templateId = md5($key);

    $selector = ['_id' => $templateId];
    $update = [
      '$inc' => ['count' => 1],
      '$set' => [
        '_id' => $templateId,
        'message' => $template,
        'severity' => $level,
        'changed' => $this->time->getCurrentTime(),
        'type' => mb_substr($type, 0, 64),
      ],
    ];
    $options = ['upsert' => TRUE];
    $templateResult = $this->database
      ->selectCollection(static::TEMPLATE_COLLECTION)
      ->updateOne($selector, $update, $options);

    // Only insert each template once per request.
    if ($this->requestTracking && !isset($this->templates[$templateId])) {
      $requestId = $this->requestStack
        ->getCurrentRequest()
        ->server
        ->get('UNIQUE_ID');

      $this->templates[$templateId] = 1;
      $track = [
        'requestId' => $requestId,
        'templateId' => $templateId,
      ];
      $this->trackerCollection()->insertOne($track);
    }
    else {
      // 24-byte format like mod_unique_id values.
      $requestId = '@@Not-a-valid-request@@';
    }

    $eventCollection = $this->eventCollection($templateId);
    if ($templateResult->getUpsertedCount()) {
      // Capped collections are actually size-based, not count-based, so "items"
      // is only a maximum, assuming event documents weigh 1kB, but the actual
      // number of items stored may be lower if items are heavier.
      // We do not use 'autoindexid' for greater speed, because:
      // - it does not work on replica sets,
      // - it is deprecated in MongoDB 3.2 and going away in 3.4.
      $options = [
        'capped' => TRUE,
        'size' => $this->items * 1024,
        'max' => $this->items,
      ];
      $this->database->createCollection($eventCollection->getCollectionName(),
        $options);

      // Do not create this index by default, as its cost is useless if request
      // tracking is not enabled.
      if ($this->requestTracking) {
        $key = ['requestTracking_id' => 1];
        $options = ['name' => 'admin-by-request'];
        $eventCollection->createIndex($key, $options);
      }
    }

    foreach ($placeholders as &$placeholder) {
      if ($placeholder instanceof MarkupInterface) {
        $placeholder = Xss::filterAdmin($placeholder);
      }
    }
    $event = [
      'hostname' => mb_substr($context['ip'] ?? '', 0, 128),
      'link' => $context['link'] ?? NULL,
      'location' => $context['request_uri'] ?? NULL,
      'referer' => $context['referer'] ?? NULL,
      'timestamp' => $context['timestamp'] ?? $this->time->getCurrentTime(),
      'user' => ['uid' => $context['uid'] ?? 0],
      'variables' => $placeholders,
    ];
    if ($this->requestTracking) {
      // Fetch the current request on each event to support subrequest nesting.
      $event['requestTracking_id'] = $requestId;
      $event['requestTracking_sequence'] = $this->sequence;
      $this->sequence++;
    }
    $eventCollection->insertOne($event);
  }

  /**
   * Ensure a collection is capped with the proper size.
   *
   * @param string $name
   *   The collection name.
   * @param int $size
   *   The collection size cap.
   *
   * @return \MongoDB\Collection
   *   The collection, usable for additional commands like index creation.
   *
   * @TODO support sharded clusters: convertToCapped does not support them.
   *
   * @throws \MongoDB\Exception\InvalidArgumentException
   * @throws \MongoDB\Exception\UnsupportedException
   * @throws \MongoDB\Exception\UnexpectedValueException
   * @throws \MongoDB\Driver\Exception\RuntimeException
   *
   * @see https://docs.mongodb.com/manual/reference/command/convertToCapped
   *
   * Note that MongoDB 4.2 still misses a proper exists() command, which is the
   * reason for the weird try/catch logic.
   *
   * @see https://jira.mongodb.org/browse/SERVER-1938
   */
  public function ensureCappedCollection(string $name, int $size): Collection {
    if ($size === 0) {
      $this->messenger->addError($this->t('Abnormal size 0 ensuring capped collection, defaulting.'));
      $size = 100000;
    }

    $collection = $this->ensureCollection($name);
    $stats = $this->database
      ->command(['collStats' => $name], static::LEGACY_TYPE_MAP)
      ->toArray()[0];
    if (!empty($stats['capped'])) {
      return $collection;
    }

    $command = [
      'convertToCapped' => $name,
      'size' => $size,
    ];
    $this->database->command($command);
    $this->messenger->addStatus($this->t('@name converted to capped collection size @size.',
      [
        '@name' => $name,
        '@size' => $size,
      ]));
    return $collection;
  }

  /**
   * Ensure a collection exists in the logger database.
   *
   * - If it already existed, it will not lose any data.
   * - If it gets created, it will be empty.
   *
   * @param string $name
   *   The name of the collection.
   *
   * @return \MongoDB\Collection
   *   The chosen collection, guaranteed to exist.
   *
   * @throws \MongoDB\Exception\InvalidArgumentException
   * @throws \MongoDB\Exception\UnsupportedException
   * @throws \MongoDB\Driver\Exception\RuntimeException
   */
  public function ensureCollection(string $name): Collection {
    $collection = $this->database
      ->selectCollection($name);

    $info = current(iterator_to_array((
    $this->database->listCollections(['filter' => ['name' => $name]])
    )));
    // If the collection doesn't exist, create it, ensuring later operations are
    // actually run after the server writes:
    // https://docs.mongodb.com/manual/reference/write-concern/#acknowledgment-behavior
    if ($info === FALSE) {
      $res = $collection->insertOne([
        '_id' => 'dummy',
        ['writeConcern' => ['w' => WriteConcern::MAJORITY, 'j' => TRUE]],
      ]);
      // With these options, all writes should be acknowledged.
      if (!$res->isAcknowledged()) {
        throw new RuntimeException("Failed inserting document during ensureCollection");
      }
      $collection->deleteMany([]);
    }

    return $collection;
  }

  /**
   * Ensure indexes are set on the collections and tracker collection is capped.
   *
   * First index is on <line, timestamp> instead of <function, line, timestamp>,
   * because we write to this collection a lot, and the smaller index on two
   * numbers should be much faster to create than one with a string included.
   */
  public function ensureSchema(): void {
    $trackerCollection = $this->ensureCappedCollection(static::TRACKER_COLLECTION,
      $this->requests * 1024);
    $indexes = [
      [
        'name' => 'tracker-request',
        'key' => ['request_id' => 1],
      ],
    ];
    $trackerCollection->createIndexes($indexes);

    $indexes = [
      // Index for adding/updating increments.
      [
        'name' => 'for-increments',
        'key' => ['line' => 1, 'changed' => -1],
      ],

      // Index for overview page without filters.
      [
        'name' => 'overview-no-filters',
        'key' => ['changed' => -1],
      ],

      // Index for overview page filtering by type.
      [
        'name' => 'overview-by-type',
        'key' => ['type' => 1, 'changed' => -1],
      ],

      // Index for overview page filtering by severity.
      [
        'name' => 'overview-by-severity',
        'key' => ['severity' => 1, 'changed' => -1],
      ],

      // Index for overview page filtering by type and severity.
      [
        'name' => 'overview-by-both',
        'key' => ['type' => 1, 'severity' => 1, 'changed' => -1],
      ],
    ];

    $this->templateCollection()->createIndexes($indexes);
  }

  /**
   * Return a collection, given its template id.
   *
   * @param string $templateId
   *   The string representation of a template \MongoId.
   *
   * @return \MongoDB\Collection
   *   A collection object for the specified template id.
   */
  public function eventCollection($templateId): Collection {
    $name = static::EVENT_COLLECTION_PREFIX . $templateId;
    if (!preg_match('/' . static::EVENT_COLLECTIONS_PATTERN . '/', $name)) {
      throw new InvalidArgumentException($this->t('Invalid watchdog template id `@id`.',
        [
          '@id' => $name,
        ]));
    }
    $collection = $this->database->selectCollection($name);
    return $collection;
  }

  /**
   * List the event collections.
   *
   * @return \MongoDB\Model\CollectionInfoIterator
   *   The collections with a name matching the event pattern.
   */
  public function eventCollections(): CollectionInfoIterator {
    $options = [
      'filter' => [
        'name' => ['$regex' => static::EVENT_COLLECTIONS_PATTERN],
      ],
    ];
    $result = $this->database->listCollections($options);
    return $result;
  }

  /**
   * Return the number of events for a template.
   *
   * @param \Drupal\mongodb_watchdog\EventTemplate $template
   *   A template for which to count events.
   *
   * @return int
   *   The number of matching events.
   */
  public function eventCount(EventTemplate $template): int {
    return $this->eventCollection($template->_id)
      ->countDocuments();
  }

  /**
   * Return the events having occurred during a given request.
   *
   * @param string $requestId
   *   The request unique_id.
   * @param int $skip
   *   The number of events to skip in the result.
   * @param int $limit
   *   The maximum number of events to return.
   *
   * @return \Drupal\mongodb_watchdog\EventTemplate|\Drupal\mongodb_watchdog\Event[]
   *   An array of [template, event] arrays, ordered by occurrence order.
   */
  public function requestEvents($requestId, $skip = 0, $limit = 0): array {
    $templates = $this->requestTemplates($requestId);
    $selector = [
      'requestTracking_id' => $requestId,
      'requestTracking_sequence' => [
        '$gte' => $skip,
        '$lt' => $skip + $limit,
      ],
    ];
    $events = [];
    $options = [
      'typeMap' => [
        'array' => 'array',
        'document' => 'array',
        'root' => '\Drupal\mongodb_watchdog\Event',
      ],
    ];

    // @var string $templateId
    // @var \Drupal\mongodb_watchdog\EventTemplate $template
    foreach ($templates as $templateId => $template) {
      $eventCollection = $this->eventCollection($templateId);
      $cursor = $eventCollection->find($selector, $options);
      /** @var \Drupal\mongodb_watchdog\Event $event */
      foreach ($cursor as $event) {
        $events[$event->requestTracking_sequence] = [
          $template,
          $event,
        ];
      }
    }

    ksort($events);
    return $events;
  }

  /**
   * Count events matching a request unique_id.
   *
   * XXX This implementation may be very inefficient in case of a request gone
   * bad generating non-templated varying messages: #requests is O(#templates).
   *
   * @param string $requestId
   *   The unique_id of the request.
   *
   * @return int
   *   The number of events matching the unique_id.
   */
  public function requestEventsCount($requestId): int {
    if (empty($requestId)) {
      return 0;
    }

    $templates = $this->requestTemplates($requestId);
    $count = 0;
    foreach ($templates as $template) {
      $eventCollection = $this->eventCollection($template->_id);
      $selector = [
        'requestTracking_id' => $requestId,
      ];
      $count += $eventCollection->countDocuments($selector);
    }

    return $count;
  }

  /**
   * Setter for limit.
   *
   * @param int $limit
   *   The limit value.
   */
  public function setLimit(int $limit): void {
    $this->limit = $limit;
  }

  /**
   * Return the number of event templates.
   *
   * @throws \ReflectionException
   */
  public function templatesCount(): int {
    return $this->templateCollection()
      ->countDocuments();
  }

  /**
   * Return an array of templates uses during a given request.
   *
   * @param string $unsafeRequestId
   *   A request "unique_id".
   *
   * @return \Drupal\mongodb_watchdog\EventTemplate[]
   *   An array of EventTemplate instances.
   *
   * @SuppressWarnings(PHPMD.UnusedFormalParameter)
   * @see https://github.com/phpmd/phpmd/issues/561
   */
  public function requestTemplates($unsafeRequestId): array {
    $selector = [
      // Variable quoted to avoid passing an object and risk a NoSQL injection.
      'requestId' => "${unsafeRequestId}",
    ];

    $cursor = $this
      ->trackerCollection()
      ->find($selector, static::LEGACY_TYPE_MAP + [
          'projection' => [
            '_id' => 0,
            'template_id' => 1,
          ],
        ]);
    $templateIds = [];
    foreach ($cursor as $request) {
      $templateIds[] = $request['template_id'];
    }
    if (empty($templateIds)) {
      return [];
    }

    $selector = ['_id' => ['$in' => $templateIds]];
    $options = [
      'typeMap' => [
        'array' => 'array',
        'document' => 'array',
        'root' => '\Drupal\mongodb_watchdog\EventTemplate',
      ],
    ];
    $templates = [];
    $cursor = $this->templateCollection()->find($selector, $options);
    /** @var \Drupal\mongodb_watchdog\EventTemplate $template */
    foreach ($cursor as $template) {
      $templates[$template->_id] = $template;
    }

    return $templates;
  }

  /**
   * Return the request events tracker collection.
   *
   * @return \MongoDB\Collection
   *   The collection.
   */
  public function trackerCollection(): Collection {
    return $this->database->selectCollection(static::TRACKER_COLLECTION);
  }

  /**
   * Return the event templates collection.
   *
   * @return \MongoDB\Collection
   *   The collection.
   */
  public function templateCollection(): Collection {
    return $this->database->selectCollection(static::TEMPLATE_COLLECTION);
  }

  /**
   * Return templates matching type and level criteria.
   *
   * @param string[] $types
   *   An array of EventTemplate types. May be a hash.
   * @param string[]|int[] $levels
   *   An array of severity levels.
   * @param int $skip
   *   The number of templates to skip before the first one displayed.
   * @param int $limit
   *   The maximum number of templates to return.
   *
   * @return \MongoDB\Driver\Cursor
   *   A query result for the templates.
   */
  public function templates(
    array $types = [],
    array $levels = [],
    $skip = 0,
    $limit = 0
  ): Cursor {
    $selector = [];
    if (!empty($types)) {
      $selector['type'] = ['$in' => array_values($types)];
    }
    if (!empty($levels) && count($levels) !== count(RfcLogLevel::getLevels())) {
      // Severity levels come back from the session as strings, not integers.
      $selector['severity'] = [
        '$in' => array_values(array_map('intval', $levels)),
      ];
    }
    $options = [
      'sort' => [
        'count' => -1,
        'changed' => -1,
      ],
      'typeMap' => [
        'array' => 'array',
        'document' => 'array',
        'root' => '\Drupal\mongodb_watchdog\EventTemplate',
      ],
    ];
    if ($skip) {
      $options['skip'] = $skip;
    }
    if ($limit) {
      $options['limit'] = $limit;
    }

    $cursor = $this->templateCollection()->find($selector, $options);
    return $cursor;
  }

  /**
   * Return the template types actually present in storage.
   *
   * @return string[]
   *   An array of distinct EventTemplate types.
   */
  public function templateTypes(): array {
    $ret = $this->templateCollection()->distinct('type');
    return $ret;
  }

}
