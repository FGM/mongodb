# Driver: `mongodb`

The `mongodb` module is the main module in the suite, providing a thin Drupal
adapter for the standard MongoDB PHP library, in the form of two Symfony factory
services and a base test class.


## Factories

The basic idea of these factory services is to provide a way to create
instances of the standard MongoDB `Client` and `Database` classes from a simple
alias string, taking all properties from the Drupal standard `Settings` object.

This allows code to be unaware of the execution environment, referring to
database or client by a functional alias, while the specifics of accessing
the relevant `mongod`/`mongos` and database are left to per-environment
settings,

* `Drupal\mongodb\DatabaseFactory` : The recommended entry point for most
  applications, returning a `MongoDb\Database` from an alias string.
    * `__construct(ClientFactory $clientFactory, Settings $settings)`. This is
      normally invoked by the container, to which the class is exposed as the
      `mongodb.database_factory` service.
    * `get(string $dbAlias): MongoDb\Database`; returns a `Database` instance
      matching the value defined in Drupal settings for `$dbAlias`.
* `Drupal\mongodb\ClientFactory`: This one may be needed for more complex
  applications, e.g. those wishing to handle operations spanning connections
  to multiple MongoDB replica sets/sharded clusters.
    * `__construct(Settings $settings)`. This is normally invoked by the
      container, to which the class is exposed as the `mongodb.client_factory`
      service.
    * `get(string $alias): MongoDb\Client`: returns a `Client` instance matching
      the value defined in Drupal settings for `$alias`.

##  Tell Drupal to use the queue backend

This module provides default queue implementation.

When you have configured basic information (host, port, ... - see install)
add this to your settings.php file:

    # Use for all queues unless otherwise specified for a specific queue.
    $settings['queue_default'] = 'queue.mongodb';

## Troubleshooting commands:

The module provides two commands designed to help troubleshoot issues and access
the contents of the MongoDB databases. Both are available for Drush and Drupal
Console indifferently.

* `drush mongodb:settings` reports how the module suite sees the settings. It
  needs no parameters, and returns YAML output looking like this:

        clients:
          default:
            uri: 'mongodb://localhost:27017'
            uriOptions: {  }
            driverOptions: {  }
        databases:
          default:
            - default
            - drupal
          keyvalue:
            - default
            - keyvalue
          logger:
            - default

* `drush mongodb:find <db_alias> <collection> <selector>` allows running a
  MongoDB query from the command line on the chosen database alias and
  collection, returning the results as YAML, as in the following example. Note
  that passing JSON selectors from the `bash` command lines requires escaping as
  in that example:

        $ drush mongodb:find keyvalue kvp_state '{ "_id": "node.min_max_update_time" }'
        -
          _id: node.min_max_update_time
          value: 'a:2:{s:11:"min_created";N;s:11:"max_created";N;}'$
        $

## Test base class

The module provides a `\Drupal\Tests\mongodb\Kernel\MongoDbTestBase` extending
core class `KernelTestBase`. This allows modules to define their own integration
tests using the module services, and taking advantage of running in a per-test
database. What it actually provides:

* Test instance properties:
    * `$this->uri` contains a MongoDB URI suitable for connecting to a default
    client, taken from the value of the `MONGODB_URI` variable is passed in the
    environment, on the PHPUnit command line, or in the `phpunit.xml`
    configuration file, this will be its value, otherwise it will be set to the
    traditional MongoDB default instance: `mongodb://localhost:27017`.
    * `$this->settings` contains a test-specific instance of core `Settings`,
    limited to the `mongodb` settings key. The default database in these
    settings is a temporary database defined by the Simpletest prefix, allowing
    it to be used without interacting with the rest of the site, and dropped
    during test teardown.
* Constants: in addition to the ones used by the `mongodb` tests, the base test
  defines constants usable by child tests:
    * `CLIENT_TEST_ALIAS` is the alias for the default MongoDB test client
    * `DB_DEFAULT_ALIAS` is the alias for the default test database
* Modules:
    * The test base enables the `mongodb` modules, since all modules using
      MongoDB are expected to depend on it.
    * Module tests will typically want their own module enabled, so they will
      need to redefine `static::$modules` including the `mongodb` module as done
      in `Drupal\Tests\mongodb_storage\Kernel\KeyValueTestBase` and
      `Drupal\Tests\mongodb_watchdog\Kernel\LoggerTest`.
* `setUp()` / `tearDown()`:
    * Tests need to invoke `parent::setUp()` near the top of their own
      `setUp()`, to have the test base define the properties before doing their
      own work.
    * Tests need to invoke `parent::tearDown()` near the end of their own
      `tearDown()` - if any - to drop the default test database.
    * Tests needing non-default test databases need to override the
      `getSettingsArray()` function to add their own database alias after
      invoking `parent::setUp()`, and need to drop that database during their
      own `tearDown()`.
        * They can use `$this->getTestDatabaseName($postfix)` method to build a
          per-test database name that will not collide with the default 
          database.
        * There are examples for this in the `mongodb_watchdoig` tests.

A complete example of how to write a test using that base class is given on the
[tests] page.

[tests]: /tests
