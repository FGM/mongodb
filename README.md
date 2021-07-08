INTRODUCTION
============

MongoDB integration for Drupal 8.9.x and 9.x, version 8.x-2.0-dev.

[![Build Status](https://travis-ci.org/fgm/mongodb.svg?branch=8.x-2.x)](https://travis-ci.org/fgm/mongodb)
[![Coverage Status](https://coveralls.io/repos/github/fgm/mongodb/badge.svg?branch=8.x-2.x)](https://coveralls.io/github/fgm/mongodb?branch=8.x-2.x)
[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/fgm/mongodb/badges/quality-score.png?b=8.x-2.x)](https://scrutinizer-ci.com/g/fgm/mongodb/?branch=8.x-2.x)

This package is a collection of several modules, allowing Drupal sites to store
various data in MongoDB, either using the provided modules, or writing their own
on top of the main `mongodb` module to take .

Module                | Information
----------------------|---------------------------------------------------------
mongodb               | Drupal/Drush wrapper around mongodb-php-library.
mongodb_storage       | Key-value storage in MongoDB.
mongodb_watchdog      | Store logger (watchdog) messages in MongoDB.

The complete documentation is available on [Github pages].

[Github pages]: https://fgm.github.io/mongodb/


LEGAL
=====

Like any Drupal module, this package is licensed under the [General Public
License, version 2.0](https://www.gnu.org/licenses/old-licenses/gpl-2.0.en.html)
or later.

* Drupal is a registered trademark of Dries Buytaert.
* Github is a registered trademark of GitHub, Inc.
* Mongo, MongoDB and the MongoDB leaf logo are registered trademarks of
  MongoDB, Inc.
