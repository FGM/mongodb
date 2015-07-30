<?php
/**
 * @file
 * Contains AliasStorage.php
 */

namespace Drupal\mongodb_path;

use contrib\mongodb\mongodb_path\src\Alias;

/**
 * Class AliasStorage
 *
 * @package Drupal\mongodb_path
 */
class AliasStorage {
  const COLLECTION_NAME = 'url_alias';

  /**
   * Pseudo-typing: defined recognized keys for aliases.
   */
  const ALIAS_KEYS = [
    '_id' => 1,
    'alias' => 1,
    'first' => 1,
    'language' => 1,
    'pid' => 1,
    'source' => 1,
  ];

  /**
   * The MongoDB collection containing the alias storage data.
   *
   * @var \MongoCollection
   */
  protected $collection;

  /**
   * The MongoDB database holding the alias storage collection.
   *
   * @var \MongoDB
   */
  protected $mongo;

  /**
   * Storage constructor.
   *
   * @param \MongoDB $mongo
   *   A MongoDB database in which to access the alias storage collection.
   */
  public function __construct(\MongoDB $mongo) {
    $this->mongo = $mongo;
    $this->collection = $mongo->selectCollection(static::COLLECTION_NAME);
  }

  /**
   * Drop the MongoDB collection underlying the alias storage.
   */
  public function drop() {
    $this->collection->drop();
    $this->collection = NULL;
  }

  /**
   * Delete a path alias from MongoDB storage.
   *
   * @param array $criteria
   *   Unlike path_delete(), this method required a criteria array.
   */
  public function delete(array $criteria) {
    $criteria = array_intersect_key($criteria, static::ALIAS_KEYS);
    $this->collection->remove($criteria);
  }

  /**
   * Load a path alias from MongoDB storage.
   *
   * @param array $conditions
   *   Unlike path_load(), this method required a criteria array.
   */
  public function load(array $conditions) {
    dsm($conditions, __METHOD__);
  }

  /**
   * Save the path to the MongoDB storage.
   *
   * Because this plugin acts as a caching layer, we just fire and forget the
   * write : even if it fails to commit before the next use, the standard SQL
   * layer will still be there to provide data.
   *
   * @param array $path
   *   The path to insert or update.
   */
  public function save(array $path) {
    $options = [
      // This should not matter, as alias are presumed to match uniquely.
      'multiple' => FALSE,

      'upsert' => TRUE,
      'w' => 1,
    ];

    $criterium = array_intersect_key($path, ['pid' => 1]);
    $path = array_intersect_key($path, static::ALIAS_KEYS);
    if (!isset($path['first'])) {
      $path['first'] = strtok($path['source'], '/');
    }

    $this->collection->update($criterium, $path, $options);
  }

  /**
   * Create the collection and its indexes if needed.
   *
   * Document minimal structure is:
   * - first : the first segment of the system path, for the whitelist
   * - langcode: the langcode for an alias
   * - source: the system path for an alias/langcode
   * - alias: the alias for a source/langcode
   */
  public function ensureSchema() {
    $collection = $this->mongo->selectCollection(static::COLLECTION_NAME);

    // This one is just an accelerator, so there is no need to wait on it.
    $collection->createIndex([
      'first' => 1,
    ], [
      'background' => TRUE,
    ]);

    // These ones are structural: they need to be valid to ensure uniqueness,
    // so they cannot be built in the background.
    $options = [
      'unique' => TRUE,
      'background' => FALSE,
    ];
    $collection->createIndex([
      'pid' => 1,
    ], $options);

    $options = [
      'unique' => FALSE,
      'background' => FALSE,
    ];
    $collection->createIndex([
      'alias' => 1,
      'language' => 1,
      'pid' => 1,
    ], $options);

    $collection->createIndex([
      'source' => 1,
      'language' => 1,
      'pid' => 1,
    ], $options);
  }
  
}