# Installation and Settings
## Prerequisites

The MongoDB module and sub-modules need some configuration to be useful. This
guide assumes that :

* A [MongoDB][download] 4.0 to 4.2.x server instance has already been installed,
  configured and is available for connection from the Drupal instance.
* The site will be running [Drupal][drupal] 8.8.x or 8.9.x, with [Drush][drush]
  10.x.
* The [mongodb][mongodb] (not [mongo][mongo]) PHP extension version 1.7 or
  later is installed and configured.
* PHP is version 7.2.x to 7.3.x. PHP 7.4.x might work but is not tested: be sure
  to [report any issue][report] you could have with it.
* We recommend [using Composer](#installing-using-composer) for installing this
  module.

Check out the [MongoDB extension and library for PHP][PHPMongoDBlib]

Installing MongoDB itself is best explained in these official resources
maintained by MongoDB Inc.:

   * [MongoDB Mac installation][MongoDBMac]
   * [MongoDB LINUX installation][MongoDBLinux]
   * [MongoDB Windows installation][MongoDBWindows]

MongoDB below 4.0 is no longer supported, which means you can no longer get
a basic web admin interface by running `mongod` with the `–httpinterface`: that
feature was [removed in 3.6][removedhttp]. To some extent, it has been superseded
by the [free monitoring][freemonitoring] service offered by MongoDB Inc.

[download]: https://www.mongodb.org/downloads
[drupal]: https://www.drupal.org/download
[drush]: https://www.drush.org/
[php]: http://php.net/downloads.php
[mongo]: http://php.net/mongo
[mongodb]: http://php.net/mongodb
[report]: https://www.drupal.org/node/add/project-issue/mongodb
[PHPMongoDBlib]: https://github.com/mongodb/mongo-php-library
[MongoDBMac]: https://docs.mongodb.com/manual/tutorial/install-mongodb-on-os-x/
[MongoDBLinux]: https://docs.mongodb.com/manual/administration/install-on-linux/
[MongoDBWindows]: https://docs.mongodb.com/manual/tutorial/install-mongodb-on-windows/
[removedhttp]: https://docs.mongodb.com/manual/release-notes/3.6-compatibility/#http-interface-and-rest-api
[freemonitoring]: https://docs.mongodb.com/manual/administration/free-monitoring/


## Settings Configuration

* Download the module package, as per
  [Installing contributed modules (Drupal 8)][install]
* Copy the relevant section from `mongodb/example.settings.local.php` to your
  `settings.local.php` file if you use one, or `settings.php` otherwise,
  and adapt it to match your MongoDB settings. These settings are used by the
  `mongodb` module to connect to your MongoDB servers, with the `default` server
  being the one started in previous steps.
  * The `clients` key contains an associative array of connection by
    connection alias, with the default connection parameters being under the
    `default` key, and additional keys allowing the use of other
    servers/clusters.
  * The `databases` key contains an associative array of server/database pairs
    by database alias, with the default Drupal database being under the
    `default` key, and additional keys allowing modules to use their own
    database to avoid stepping on each other's toes. This is especially useful
    for bespoke modules created for the needs of a specific site, which can thus
    use their own databases, possibly located on other MongoDB clusters.
    For example, with the following settings:

```php
// In sites/default/settings.local.php.
$settings['mongodb'] = [
  'clients' => [
    // Client alias => connection constructor parameters.
    'default' => [
      'uri' => 'mongodb://localhost:27017',
      'uriOptions' => [],
      'driverOptions' => [],
    ],
  ],
  'databases' => [
    // Database alias => [ client_alias, database_name ]
    'default' => ['default', 'drupal'],
    'keyvalue' => ['default', 'keyvalue'],
    'logger' => ['default', 'logger'],
    'queue' => ['default', 'queue'],
  ],
];
```
  * With these settings:
    * The `default` database alias will handle collections in the `drupal`
      database on the `default` MongoDB server installed in earlier steps.
    * The `keyvalue` database alias will store key-value collections on the
      same `default` MongoDB server, but in a separate `keyvalue` database.
    * The `logger` database alias will store logger collections on the same
      `default` MongoDB server, but in a separate `logger` database.
    * The `queue` database alias will store queue collections on the same
          `default` MongoDB server, but in a separate `queue` database. 

The module contains an example default implementation of these settings, which
you can copy or include, in `mongodb/example.settings.local.php`.

Once the module is installed and enabled, you can check its requirements on
`/admin/reports/status`:

![MongoDB on status page](images/mongodb-requirements.png)


## Installing using Composer

If you are already using [Composer][composer] in your site to manage module
dependencies, installing is just a two-steps process:

* At the root of your site, add this package:

        # Stable version
        composer require "drupal/mongodb:^2.0.0"

        # Latest version
        composer require "drupal/mongodb:dev-2.x"

* Enable the `mongodb` module. You now have access to the MongoDB services and
  Drush/Console commands for the `mongodb` module.
* Optionally, enabled the [`mongodb_storage`](modules/mongodb_storage.md) and
  [`mongodb_watchdog`](modules/mongodb_watchdog.md) module for
  additional services and commands.

[composer]: https://www.drupal.org/docs/develop/using-composer/using-composer-to-manage-drupal-site-dependencies
[install]: https://www.drupal.org/docs/8/extending-drupal-8/installing-drupal-8-modules
