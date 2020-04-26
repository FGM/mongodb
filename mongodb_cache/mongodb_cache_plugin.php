<?php

namespace Drupal\mongodb_cache;

/*
 * This is the actual MongoDB cache backend.
 *
 * - It replaces the core cache backend file. See README.md for details.
 * - It cannot abide by PSR-1 "side-effects or symbols" rule because of the low
 *   level at which it operates, where the autoloader is not available.
 */

include_once __DIR__ . '/../mongodb.module';

/**
 * MongoDB cache implementation.
 *
 * This is Drupal's default cache implementation. It uses the MongoDB to store
 * cached data. Each cache bin corresponds to a collection by the same name.
 */
class Cache implements \DrupalCacheInterface {
  /**
   * The name of the collection holding the cache data.
   *
   * @var string
   */
  protected $bin;

  /**
   * A closure wrapping MongoBinData::__construct() with its default $type.
   *
   * @var \Closure
   */
  protected $binDataCreator;

  /**
   * The collection holding the cache data.
   *
   * @var \MongoCollection|\MongoDebugCollection|\MongodbDummy
   */
  protected $collection;

  /**
   * Has a connection exception already been notified ?
   *
   * @var bool
   *
   * @see \Drupal\mongodb_cache\Cache::notifyException()
   * @see \Drupal\mongodb_cache\Cache::hasException()
   *
   * This is a static, because the plugin assumes that connection errors will be
   * share between all bins, under the hypothesis that all bins will be using
   * the same connection.
   */
  protected static $isExceptionNotified = FALSE;

  /**
   * The default write options for this collection: unsafe mode.
   *
   * @var array
   *
   * @see self::__construct()
   */
  protected $unsafe;

  /**
   * The name of the state variable holding the latest bin expire timestamp.
   *
   * @var string
   */
  protected $flushVarName;

  /**
   * The number of seconds during which a new flush will be ignored.
   *
   * @var int
   *
   * @see self::__construct()
   */
  protected $stampedeDelay;

  /**
   * Constructor.
   *
   * @param string $bin
   *   The name of the cache bin for which to build a backend.
   */
  public function __construct($bin) {
    $this->bin = $bin;
    try {
      $this->collection = mongodb_collection($bin);
    }
    catch (\MongoConnectionException $e) {
      static::notifyException($e);
      $this->collection = new \MongodbDummy();
    }

    // Default is FALSE: this is a cache, so a missed write is not an issue.
    $this->unsafe = mongodb_default_write_options(FALSE);

    $this->stampedeDelay = variable_get('mongodb_cache_stampede_delay', 5);
    $this->flushVarName = "flush_cache_{$bin}";

    $this->binDataCreator = $this->getBinDataCreator();
  }

  /**
   * Display an exception error message only once.
   *
   * @param \MongoException $e
   *   The exception to notify to the user.
   */
  protected static function notifyException(\MongoException $e) {
    if (!self::$isExceptionNotified) {
      drupal_set_message(t('MongoDB cache problem %exception.', [
        '%exception' => $e->getMessage(),
      ]), 'error');
      self::$isExceptionNotified = TRUE;
    }
  }

  /**
   * An alternate \MongoBinData constructor using default $type.
   *
   * @param mixed $data
   *   The data to convert to \MongoBinData.
   *
   * @return \Closure
   *   The alternate constructor with $type following the extension version.
   */
  protected function createBinData($data) {
    $creator = $this->binDataCreator;
    $result = $creator($data);
    return $result;
  }

  /**
   * Return the proper MongoBinData constructor with its type argument.
   *
   * The signature of \MongoBinData::__construct() changed in 1.2.11 to require
   * $type and default to BYTE_ARRAY, then again in 1.5.0 to default to GENERIC.
   *
   * @return \Closure
   *   A closure wrapping the constructor with its expected $type.
   */
  protected function getBinDataCreator() {
    $mongoVersion = phpversion('mongo');
    if (version_compare($mongoVersion, '1.2.11') < 0) {
      $result = function ($data) {
        return new \MongoBinData($data);
      };
    }
    else {
      $type = version_compare($mongoVersion, '1.5.0') < 0
        ? \MongoBinData::BYTE_ARRAY
        : \MongoBinData::GENERIC;
      $result = function ($data) use ($type) {
        return new \MongoBinData($data, $type);
      };
    }

    return $result;
  }

  /**
   * Return the timestamp of the latest flush.
   *
   * @return int
   *   A UNIX timestamp.
   */
  protected function getFlushTimestamp() {
    $result = intval(variable_get($this->flushVarName, 0));
    return $result;
  }

  /**
   * Record a timestamp as marking the latest flush for the current bin.
   *
   * As this performs a variable_set(), it is a costly operation.
   *
   * @param int $timestamp
   *   A UNIX timestamp. May be 0.
   */
  protected function setFlushTimestamp($timestamp) {
    variable_set($this->flushVarName, $timestamp);
  }

  /**
   * {@inheritdoc}
   */
  public function get($cid) {
    try {
      // Garbage collection necessary when enforcing a minimum cache lifetime.
      $this->garbageCollection();

      $cache = $this->collection->findOne(['_id' => (string) $cid]);
      $result = $this->prepareItem($cache);
    }
    catch (\MongoConnectionException $e) {
      self::notifyException($e);
      $result = FALSE;
    }

    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function getMultiple(&$cids) {
    $cache = [];
    try {
      // Garbage collection necessary when enforcing a minimum cache lifetime.
      $this->garbageCollection();

      $criteria = [
        '_id' => [
          '$in' => array_map('strval', $cids),
        ],
      ];
      $result = $this->collection->find($criteria);

      foreach ($result as $item) {
        $item = $this->prepareItem($item);
        if ($item) {
          $cache[$item->cid] = $item;
        }
      }
      $cids = array_diff($cids, array_keys($cache));
    }
    catch (\MongoConnectionException $e) {
      self::notifyException($e);
    }

    return $cache;
  }

  /**
   * Garbage collection for get() and getMultiple().
   */
  protected function garbageCollection() {
    // Garbage collection only required when enforcing a minimum cache lifetime.
    $flush_timestamp = $this->getFlushTimestamp();
    if ($flush_timestamp && ($flush_timestamp + variable_get('cache_lifetime', 0) <= REQUEST_TIME)) {
      // Reset the variable immediately to prevent a meltdown under heavy load.
      $this->setFlushTimestamp(0);

      // Remove non-permanently cached items from the collection.
      $criteria = [
        'expire' => [
          '$lte' => new \MongoDate($flush_timestamp),
          '$ne' => CACHE_PERMANENT,
        ],
      ];
      try {
        $this->collection->remove($criteria, $this->unsafe);
      }
      catch (\MongoException $e) {
        self::notifyException($e);
      }

      // Re-enable the expiration mechanism.
      $this->setFlushTimestamp(REQUEST_TIME + $this->stampedeDelay);
    }
  }

  /**
   * Prepare a cached item.
   *
   * Checks that items are either permanent not yet expired, unserializes
   * data as appropriate and converts MongoDB native dates to timestamps.
   *
   * @param array|null $cache
   *   An item loaded from cache_get() or cache_get_multiple().
   *
   * @return false|object
   *   The item with data unserialized as appropriate or FALSE if there is no
   *   valid item to load.
   */
  protected function prepareItem($cache) {
    if (!$cache || !isset($cache['data'])) {
      return FALSE;
    }

    unset($cache['_id']);
    $cache = (object) $cache;

    // Provide backwards compatibility for NumberLong field types.
    if ($cache->created instanceof \MongoDate) {
      $cache->created = $cache->created->toDateTime()->getTimestamp();
    }
    if ($cache->expire instanceof \MongoDate) {
      $cache->expire = $cache->expire->toDateTime()->getTimestamp();
    }

    // If enforcing a minimum cache lifetime, validate that the data is
    // currently valid for this user before we return it by making sure the
    // cache entry was created before the timestamp in the current session's
    // cache timer. The cache variable is loaded into the $user object by
    // _drupal_session_read() in session.inc. If the data is permanent or we're
    // not enforcing a minimum cache lifetime always return the cached data.
    if ($cache->expire != CACHE_PERMANENT && variable_get('cache_lifetime', 0)
      && isset($_SESSION['cache_expiration'][$this->bin])
      && $_SESSION['cache_expiration'][$this->bin] > $cache->created) {
      // These cached data are too old and thus not valid for us, ignore it.
      return FALSE;
    }
    if ($cache->data instanceof \MongoBinData) {
      $cache->data = $cache->data->bin;
    }
    if ($cache->serialized) {
      $cache->data = unserialize($cache->data);
    }

    return $cache;
  }

  /**
   * {@inheritdoc}
   */
  public function set($cid, $data, $expire = CACHE_PERMANENT) {
    $scalar = is_scalar($data);
    $entry = array(
      '_id' => (string) $cid,
      'cid' => (string) $cid,
      'created' => new \MongoDate(REQUEST_TIME),
      'expire' => new \MongoDate($expire),
      'serialized' => !$scalar,
      'data' => $scalar ? $data : serialize($data),
    );

    // Use MongoBinData for non-UTF8 strings.
    if (is_string($entry['data']) && !drupal_validate_utf8($entry['data'])) {
      $entry['data'] = $this->createBinData($entry['data']);
    }

    try {
      $this->collection->save($entry, $this->unsafe);
    }
    // Multiple possible exceptions on save(), not just connection-related.
    catch (\MongoException $e) {
      self::notifyException($e);
      // The database may not be available, so we'll ignore cache_set requests.
    }
  }

  /**
   * Attempt removing data from the collection, notifying on exceptions.
   *
   * @param array|null $criteria
   *   NULL means to remove all documents from the collection.
   */
  protected function attemptRemove($criteria = NULL) {
    try {
      if ($criteria === []) {
        $this->collection->drop();
      }
      else {
        $this->collection->remove($criteria, $this->unsafe);
      }
    }
    catch (\MongoException $e) {
      self::notifyException($e);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function clear($cid = NULL, $wildcard = FALSE) {
    $minimum_lifetime = variable_get('cache_lifetime', 0);

    if (empty($cid)) {
      if ($minimum_lifetime) {
        // We store the time in the current user's $user->cache variable which
        // will be saved into the sessions bin by _drupal_session_write(). We
        // then simulate that the cache was flushed for this user by not
        // returning cached data that was cached before the timestamp.
        $_SESSION['cache_expiration'][$this->bin] = REQUEST_TIME;

        $flush_timestamp = $this->getFlushTimestamp();
        if (empty($flush_timestamp)) {
          // This is the first request to clear the cache, start a timer.
          $this->setFlushTimestamp(REQUEST_TIME);
        }
        elseif (REQUEST_TIME > ($flush_timestamp + $minimum_lifetime)) {
          // Clear the cache for everyone, cache_lifetime seconds have passed
          // since the first request to clear the cache.
          $criteria = [
            'expire' => [
              '$ne' => CACHE_PERMANENT,
              '$lte' => new \MongoDate(REQUEST_TIME),
            ],
          ];
          $this->attemptRemove($criteria);
          $this->setFlushTimestamp(REQUEST_TIME + $this->stampedeDelay);
        }
      }
      else {
        // No minimum cache lifetime, flush all temporary cache entries now.
        $criteria = [
          'expire' => [
            '$ne' => CACHE_PERMANENT,
            '$lte' => new \MongoDate(REQUEST_TIME),
          ],
        ];
        $this->attemptRemove($criteria);
      }
    }
    else {
      if ($wildcard) {
        if ($cid == '*') {
          $criteria = [];
          $this->attemptRemove($criteria);
        }
        else {
          $criteria = [
            'cid' => new \MongoRegex('/' . preg_quote($cid) . '.*/'),
          ];
          $this->attemptRemove($criteria);
        }
      }
      elseif (is_array($cid)) {
        // Delete in chunks in case a large array is passed.
        do {
          $criteria = [
            'cid' => [
              '$in' => array_map('strval', array_splice($cid, 0, 1000)),
            ],
          ];
          $this->attemptRemove($criteria);
        } while (count($cid));
      }
      else {
        $criteria = [
          '_id' => (string) $cid,
        ];
        $this->attemptRemove($criteria);
      }
    }
  }

  /**
   * Has the plugin thrown an exception at any point ?
   *
   * @retun bool
   *   Has it ?
   *
   * @see mongodb_cache_exit()
   */
  public static function hasException() {
    return static::$isExceptionNotified;
  }

  /**
   * {@inheritdoc}
   */
  public function isEmpty() {
    try {
      // Faster than findOne().
      $result = !$this->collection->find([], ['_id' => 1])->limit(1)->next();
    }
    catch (\MongoConnectionException $e) {
      // An unreachable cache is as good as empty.
      $result = TRUE;
      self::notifyException($e);
    }

    return $result;
  }

}
