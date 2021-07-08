# PHPUnit

The newly ported modules have some test coverage, which can be checked with
PHPUnit.

[![Coverage Status](https://coveralls.io/repos/github/fgm/mongodb/badge.svg?branch=8.x-2.x)](https://coveralls.io/github/fgm/mongodb?branch=8.x-2.x)


## Writing custom tests

The `mongodb` module provides a `Drupal\Tests\mongodb\Kernel\MongoDbTestBase`
base class on which to build custom kernel tests for [bespoke] modules, as it
provides a per-test database created during test <code>setUp()</code>, and
dropped during <code>tearDown()</code>.

The base class is documented on the [mongodb] documentation page.


## Complete test example

This example show how to write a test using a custom `foo` database for the
eponymous module `foo`, assuming individual tests do not drop the database
instance themselves.

    <?php

    namespace Drupal\\Tests\foo\Kernel;

    use Drupal\mongodb\MongoDb;
    use Drupal\Tests\mongodb\Kernel\MongoDbTestBase;

    /**
     * @coversDefaultClass \Drupal\foo\Foo
     *
     * @group foo
     */
    class FooTest extends MongoDbTestBase {
      const MODULE = 'foo';

      protected static $modules = [
        MongoDb::MODULE,
        static::MODULE,
      ];

      /**
       * The test database.
       */
      protected $database;

      /**
       * Override getSettingsArray method to include custom test database
       */
      public function getSettingsArray(): array {
        $settings = parent::getSettingsArray();
        $settings[MongoDb::MODULE]['databases']['foo_alias'] = [
          static::CLIENT_TEST_ALIAS,
          $this->getDatabasePrefix(),
        ];

        return $settings;
      }

      /**
       * Add a custom alias to settings and instantiate a custom database.
       *
       * If the tests do not need a specific database, no setUp()/tearDown() is
       * even needed.
       */
      public function setUp(): void {
        parent::setUp();
        $this->database = new DatabaseFactory(
          new ClientFactory($this->settings),
          $this->settings
        )->get(static::MODULE);
      }

      /**
       * Drop the custom database.
       *
       * If the tests do not need a specific database, no setUp()/tearDown() is
       * even needed.
       */
      public function tearDown(): void {
        $this->database->drop();
        parent::tearDown();
      }

      /**
       * @covers ::whatever
       */
      public function testWhatever() {
        // ... custom test logic...
      }

    }

In most cases, modules implementing will implement multiple classes, hence have
multiple tests, in which case having a per-module base test class will be
recommended. See `mongodb_storage` or `mongodb_watchdog` tests for examples.

[bespoke]: /bespoke
[mongodb]: /modules/mongodb


## Running tests

Now that Simpletest is for all intents and purposes deprecated since Drupal 8.6,
and its UI apparently going away (cf [#2566767]), tests should be run from the
PHPUnit command line.

[#2566767]: https://www.drupal.org/node/2566767


### Running directly

The typical full command to run tests looks like the next example (`\` is to
avoid too long a line). Assuming a `composer-project` deployment with Drupal in
the `web/` directory, you'll need to run phpunit from the Drupal root, not the
project root:

    cd web
    SIMPLETEST_BASE_URL=http://localhost                \
    BROWSERTEST_OUTPUT_DIRECTORY=/some/writable/pre-existing/path \
    SIMPLETEST_DB=mysql://user:pass@localhost/drupal8   \
    MONGODB_URI=mongodb://somemongohost:27017           \
    ../vendor/bin/phpunit -c $PWD/core/phpunit.xml.dist \
        -v --debug --coverage-clover=/tmp/cover.xml     \
        modules/contrib/mongodb

* Functional tests: the `SIMPLETEST_BASE_URL` and `BROWSERTEST_OUTPUT_DIRECTORY`
  variables are needed. Kernel and Unit tests do not need them.
* Optional: `MONGODB_URI` points to a working MongoDB instance. If it is not
  provided, the tests will default to `mongodb://localhost:27017`.

These variables can also be set in the `core/phpunit.xml` custom configuration
file to simplify the command line, as described on Drupal.org [Running PHPUnit tests]
page.

[Running PHPUnit tests]: https://www.drupal.org/node/2116263


### Using a `phpunit.xml` configuration file

The test command can also be simplified using a `phpunit.xml` configuration file:

    phpunit -c core/phpunit.xml

Or to generate a coverage report:

    phpunit -c core/phpunit.xml --coverage-html=/some/coverage/path modules/contrib/mongodb

In this syntax, `core/phpunit.xml` is a local copy of the default
`mongodb/core.phpunit.xml` configuration file, tweaked for the local
environment.
