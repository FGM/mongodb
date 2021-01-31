<?php
declare(strict_types = 1);

namespace Drupal\mongodb\Queue;

use Drupal\Core\Queue\QueueInterface;
use Drupal\mongodb\MongoDb;
use MongoDB\Database;

/**
 * Mongodb queue implementation.
 *
 * @ingroup queue
 */
class MongodbQueue implements QueueInterface {

  /**
   * The queue storage.
   *
   * @var \MongoDB\Database
   */
  protected $database;

  /**
   * The collection name for the queue.
   *
   * @var \MongoDB\Collection[] $collection
   */
  protected $collection;

  /**
   * Constructs a \Drupal\mongodb\Queue\MongodbQueue object.
   *
   * @param string $name
   *   The name of the queue.
   * @param array $settings
   *   Array of Mongodb-related settings for this queue.
   * @param \MongoDB\Database $database
   *   The database object.
   */
  public function __construct($name, $settings, Database $database) {
    $this->name = $name;
    $this->database = $database;
    $this->collection = $this->database
      ->selectCollection($name);
  }

  /**
   * {@inheritdoc}
   */
  public function createItem($data) {
    try {
      $id = $this->doCreateItem($data);
    }
    catch (\Exception $e) {
        throw $e;
    }

    return $id;
  }

  /**
   * Adds a queue item and store it directly to the queue.
   *
   * @param $data
   *   Arbitrary data to be associated with the new task in the queue.
   *
   * @return
   *   A unique ID if the item was successfully created and was (best effort)
   *   added to the queue, otherwise FALSE. We don't guarantee the item was
   *   committed to disk etc, but as far as we know, the item is now in the
   *   queue.
   */
  protected function doCreateItem($data) {

    $item = [
      'name' => $this->name,
      'data' => $data,
      'expire' => 0,
      'created' => time(),
    ];

    $result = $this->collection->insertOne($item);

    return $result->getInsertedId();
  }

  /**
   * {@inheritdoc}
   */
  public function numberOfItems() {

    try {
      return (int) MongoDb::countCollection($this->collection);
    }
    catch (\Exception $e) {
      throw $e;
      // If there is no collection there cannot be any items.
      return 0;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function claimItem($lease_time = 30) {
    $newobj = [
      'expire' => time() + $lease_time,
    ];
    return $this->collection->findOneAndUpdate(
      [],
      [ '$set' => $newobj ],
      [ 'sort' => ['created' => 1],]
    );


  }

  /**
   * {@inheritdoc}
   */
  public function releaseItem($item) {
    return $this->collection
      ->updateOne(
        ['_id' => $item->_id],
        ['$set' =>
          [
            'expire' => 0
          ],
        ]
      );
  }

  /**
   * {@inheritdoc}
   */
  public function deleteItem($item) {
    try {
      $this->collection
        ->deleteOne(
          [
            '_id' => $item->_id
          ]
        );
    }
    catch (\Exception $e) {
      throw $e;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function createQueue() {
    // Create the index.
    $this->collection->createIndex([
      'expire' => 1,
      'created' => 1
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function deleteQueue() {
    try {
      $this->collection->drop();
    }
    catch (\Exception $e) {
      throw $e;
    }
  }

}
