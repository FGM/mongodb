<?php

namespace Drupal\mongodb\Tests\Kernel;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\mongodb\ClientFactory;
use MongoDB\Driver\Exception\ConnectionTimeoutException;

/**
 * Class ClientFactoryTest.
 *
 * @covers \Drupal\mongodb\ClientFactory
 * @coversDefaultClass \Drupal\mongodb\ClientFactory
 *
 * @group MongoDB
 */
class ClientFactoryTest extends MongoDbTestBase {

  /**
   * Test a normal client creation attempt.
   */
  public function testGetHappy() {
    $clientFactory = new ClientFactory($this->settings);

    try {
      $client = $clientFactory->get(static::CLIENT_TEST_ALIAS);
      // Force connection attempt by executing a command.
      $client->listDatabases();
    }
    catch (ConnectionTimeoutException $e) {
      $this->fail(new FormattableMarkup('Could not connect to server on @uri. Enable one on @default or specify one in MONGODB_URI.', [
        '@default' => static::DEFAULT_URI,
        '@uri' => $this->uri,
      ]));
    }
    catch (\Exception $e) {
      $this->fail($e->getMessage());
    }
  }

  /**
   * Test an existing alias pointing to an invalid server.
   */
  public function testGetSadBadAlias() {
    $clientFactory = new ClientFactory($this->settings);

    try {
      $client = $clientFactory->get(static::CLIENT_BAD_ALIAS);
      // Force connection attempt by executing a command.
      $client->listDatabases();
      $this->fail('Should not have been able to connect to a non-server.');
    }
    catch (ConnectionTimeoutException $e) {
      $this->assertTrue(TRUE, 'Cannot create a client to a non-server.');
    }
    catch (\Exception $e) {
      $this->fail($e->getMessage());
    }
  }

}
