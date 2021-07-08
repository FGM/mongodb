<?php

declare(strict_types=1);

namespace Drupal\Tests\mongodb_watchdog\Functional;

use Drupal\Core\Logger\RfcLogLevel;
use Drupal\Core\Site\Settings;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\mongodb\MongoDb;
use Drupal\mongodb_watchdog\Logger;
use Drupal\Tests\BrowserTestBase;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Symfony\Component\HttpFoundation\Response;

/**
 * Class ControllerTest.
 *
 * @group MongoDB
 */
class ControllerTest extends BrowserTestBase {
  use StringTranslationTrait;

  const DEFAULT_URI = 'mongodb://localhost:27017';
  const CLIENT_TEST_ALIAS = 'test';

  const DB_DEFAULT_ALIAS = 'default';

  const PATH_DENIED = '/admin/reports/mongodb/watchdog/access-denied';
  const PATH_EVENT_BASE = "/admin/reports/mongodb/watchdog/";
  const PATH_NOT_FOUND = '/admin/reports/mongodb/watchdog/page-not-found';
  const PATH_OVERVIEW = 'admin/reports/mongodb/watchdog';

  /**
   * Map of PSR3 log constants to RFC 5424 log constants.
   *
   * @var array
   */
  const LEVEL_TRANSLATION = [
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
   * These modules need to be enabled.
   *
   * @var array
   */
  protected static $modules = [
    // Needed to check admin/help/mongodb.
    'help',
    MongoDb::MODULE,
    Logger::MODULE,
  ];

  /**
   * An administrator account.
   *
   * @var \Drupal\user\Entity\User
   */
  protected $adminUser;

  /**
   * A basic authenticated user account.
   *
   * @var \Drupal\user\Entity\User
   */
  protected $anyUser;

  /**
   * An administrator-type user account, but not an administrator.
   *
   * @var \Drupal\user\Entity\User
   */
  protected $bigUser;

  /**
   * The event templates collection.
   *
   * @var \MongoDB\Collection
   */
  protected $collection;

  /**
   * The default theme, needed after 8.8.0.
   *
   * @var string
   *
   * @see https://www.drupal.org/node/3083055
   */
  protected $defaultTheme = 'stark';

  /**
   * The time the test started, simulating a request time.
   *
   * @var int
   */
  protected $requestTime;

  /**
   * The site base URI.
   *
   * @var string
   */
  protected $uri;

  /**
   * Remove all Drupal markup placeholders.
   *
   * @param string $message
   *   The raw message.
   *
   * @return string
   *   The replacement message.
   */
  protected static function neuter(string $message): string {
    return str_replace(['{', '}', '@', '%', ':'], '', $message);
  }

  /**
   * {@inheritdoc}
   *
   * Configure settings and create users with specific permissions.
   *
   * @see \Drupal\Tests\mongodb_watchdog\Functional\ControllerTest::writeSettings()
   */
  public function setUp(): void {
    // $_ENV if it comes from phpunit.xml <env>
    // $_SERVER if it comes from the phpunit command line environment.
    $this->uri = $_ENV['MONGODB_URI']
      ?? $_SERVER['MONGODB_URI']
      ?? static::DEFAULT_URI;

    // This line customizes the parent site; ::writeSettings the child site.
    $this->settings = new Settings([
      MongoDb::MODULE => $this->getSettingsArray(),
    ]);

    parent::setUp();

    // Create users.
    $this->adminUser = $this->drupalCreateUser([], 'test_admin', TRUE);
    $this->bigUser = $this->drupalCreateUser([
      'administer site configuration',
      'access administration pages',
      'access site reports',
      'administer users',
    ], 'test_honcho');
    $this->anyUser = $this->drupalCreateUser([
      'access content',
    ], 'test_lambda');

    $this->requestTime = $this->container
      ->get('datetime.time')
      ->getCurrentTime();

    try {
      $this->collection = $this->container
        ->get(MongoDb::SERVICE_DB_FACTORY)
        ->get(Logger::DB_LOGGER)
        ->selectCollection(Logger::TEMPLATE_COLLECTION);
    }
    catch (\Exception $e) {
      $this->collection = NULL;
    }
    $this->assertNotNull($this->collection, (string) $this->t('Access MongoDB watchdog collection'));
  }

  /**
   * {@inheritdoc}
   */
  public function tearDown(): void {
    // Get the database before container is torn down.
    $database = $this->container
      ->get(MongoDb::SERVICE_DB_FACTORY)
      ->get(Logger::DB_LOGGER);

    // Might trigger some more log insertions, so do not drop yet.
    parent::tearDown();

    $database->drop();
  }

  /**
   * Rewrites the settings.php file of the test site.
   *
   * @param array $settings
   *   An array of settings to write out, in the format expected by
   *   drupal_rewrite_settings().
   *
   * @throws \Exception
   *
   * @see \Drupal\Core\Test\FunctionalTestSetupTrait::writeSettings()
   */
  protected function writeSettings(array $settings) {
    // Taken from trait.
    include_once DRUPAL_ROOT . '/core/includes/install.inc';
    $filename = $this->siteDirectory . '/settings.php';

    // Customizations.
    $settings['settings'] += [
      MongoDb::MODULE => (object) [
        'value' => $this->getSettingsArray(),
        'required' => TRUE,
      ],
    ];

    // End of code taken from trait again.
    // system_requirements() removes write permissions from settings.php
    // whenever it is invoked.
    // Not using File API; a potential error must trigger a PHP warning.
    chmod($filename, 0666);
    drupal_rewrite_settings($settings, $filename);
  }

  /**
   * Prepare the Settings from a base set of MongoDB settings.
   *
   * @return array
   *   A settings array only containing MongoDB-related settings.
   */
  protected function getSettingsArray() : array {
    return [
      'clients' => [
        static::CLIENT_TEST_ALIAS => [
          'uri' => $this->uri,
          'uriOptions' => [],
          'driverOptions' => [],
        ],
      ],
      'databases' => [
        static::DB_DEFAULT_ALIAS => [static::CLIENT_TEST_ALIAS, $this->getDatabasePrefix()],
        Logger::DB_LOGGER => [static::CLIENT_TEST_ALIAS, $this->getDatabasePrefix()],
      ],
    ];
  }

  /**
   * Getter for the test database prefix.
   *
   * @return string
   *   The prefix.
   *
   * @see \Drupal\KernelTests\KernelTestBase::getDatabasePrefix()
   */
  protected function getDatabasePrefix() : string {
    return $this->databasePrefix ?? '';
  }

  /**
   * Get the log entry information form the page.
   *
   * @return array
   *   List of entries and their information.
   */
  protected function getLogEntries() : array {
    $entries = [];
    if ($table = $this->getLogsEntriesTable()) {
      /** @var \Behat\Mink\Element\NodeElement $row */
      foreach ($table as $row) {
        /** @var \Behat\Mink\Element\NodeElement[] $cells */
        $cells = $row->findAll('css', 'td');
        $entries[] = [
          'severity' => $this->getSeverityConstant($cells[2]->getAttribute('class')),
          'type' => $cells[3]->getText(),
          'message' => $cells[4]->getText(),
        ];
      }
    }
    return $entries;
  }

  /**
   * Gets the watchdog severity constant corresponding to the CSS class.
   *
   * @param string $class
   *   CSS class attribute.
   *
   * @return int|null
   *   The watchdog severity constant or NULL if not found.
   */
  protected function getSeverityConstant(string $class) : int {
    // Class: "mongodb-watchdog__severity--(level)", prefix length = 28.
    $level = substr($class, 28);
    return static::LEVEL_TRANSLATION[$level];
  }

  /**
   * Find the Logs table in the DOM.
   *
   * @return \Behat\Mink\Element\NodeElement[]
   *   The return value of a xpath search.
   */
  protected function getLogsEntriesTable() : array {
    return $this->xpath('.//table/tbody/tr');
  }

  /**
   * Asserts that the counts for displayed entries match the expected counts.
   *
   * @param array $types
   *   The type information to compare against.
   */
  protected function assertTypeCount(array $types) {
    $entries = $this->getLogEntries();
    $reducer = function ($accu, $curr) {
      $accu[$curr['type'] . '-' . $curr['severity']] = [$curr['type'], $curr['severity']];
      return $accu;
    };
    $actual = array_reduce($entries, $reducer, []);
    $expected = array_reduce($types, $reducer, []);
    $this->assertEquals($expected, $actual, "Inserted events are found on page");
  }

  /**
   * Generate dblog entries.
   *
   * @param \Psr\Log\LoggerInterface $logger
   *   The mongodb.logger service.
   * @param int $count
   *   Number of log entries to generate.
   * @param string $type
   *   The type of watchdog entry.
   * @param int $severity
   *   The severity of the watchdog entry.
   */
  private function insertLogEntries(
    LoggerInterface $logger,
    int $count,
    string $type = 'custom',
    int $severity = RfcLogLevel::EMERGENCY
  ) {
    $ip = '::1';
    $context = [
      'channel'     => $type,
      'link'        => NULL,
      'user'        => ['uid' => $this->bigUser->id()],
      'request_uri' => "http://[$ip]/",
      'referer'     => $_SERVER['HTTP_REFERER'] ?? '',
      'ip'          => $ip,
      'timestamp'   => $this->requestTime,
    ];
    $message = $this->randomString();
    for ($i = 0; $i < $count; $i++) {
      $logger->log($severity, $message, $context);
    }
  }

  /**
   * Verify the logged-in user has the desired access to the log report.
   *
   * @param int $statusCode
   *   HTTP status code.
   *
   * @throws \Behat\Mink\Exception\ExpectationException
   * @throws \Behat\Mink\Exception\ResponseTextException
   *
   * The first of the assertions would really belong in a functional test for
   * the mongodb module. But until it gets a functional test, keeping it here
   * saves some test running time over having one more functional test in
   * mongodb module just for this.
   */
  private function verifyReports($statusCode = Response::HTTP_OK) {
    // View MongoDB help page.
    $this->drupalGet('/admin/help');
    $session = $this->assertSession();
    $session->statusCodeEquals($statusCode);
    if ($statusCode == Response::HTTP_OK) {
      $session->pageTextContains('MongoDB');
    }

    $this->drupalGet('/admin/help/mongodb');
    $session = $this->assertSession();
    $session->statusCodeEquals($statusCode);
    if ($statusCode == Response::HTTP_OK) {
      // DBLog help was displayed.
      $session->pageTextContains('implements a generic interface');
    }

    // View MongoDB watchdog overview report.
    $this->drupalGet(static::PATH_OVERVIEW);
    $session = $this->assertSession();
    $session->statusCodeEquals($statusCode);
    if ($statusCode == Response::HTTP_OK) {
      // MongoDB watchdog report was displayed.
      $expectedTexts = [
        'Recent log messages in MongoDB',
        'Filter log messages',
        'Type',
        'Severity',
        'Latest',
        'Severity',
        'Message',
        'Source',
      ];
      foreach ($expectedTexts as $expectedText) {
        $session->pageTextContains($expectedText);
      }
    }

    // View MongoDB watchdog page-not-found report.
    $this->drupalGet(self::PATH_NOT_FOUND);
    $session = $this->assertSession();
    $session->statusCodeEquals($statusCode);
    if ($statusCode == Response::HTTP_OK) {
      // MongoDB watchdog page-not-found report was displayed.
      $session->pageTextContains("Top 'page not found' errors in MongoDB");
    }

    // View MongoDB watchdog access-denied report.
    $this->drupalGet(static::PATH_DENIED);
    $session = $this->assertSession();
    $session->statusCodeEquals($statusCode);
    if ($statusCode == Response::HTTP_OK) {
      // MongoDB watchdog access-denied report was displayed.
      $session->pageTextContains("Top 'access denied' errors in MongoDB");
    }

    // Create an event to ensure an event page exists, using the standard PSR-3
    // service instead of the Drupal logger channel to ensure getting this
    // logger with its specific features.
    $expectedMessage = $this->randomString(32);
    /** @var \Drupal\mongodb_watchdog\Logger $logger */
    $logger = $this->container->get(Logger::SERVICE_LOGGER);
    $logger->info($expectedMessage, ['with' => 'context']);

    $selector = ['message' => $expectedMessage];
    $event = $logger->templateCollection()
      ->findOne($selector, MongoDb::ID_PROJECTION);
    $eventId = $event['_id'];

    // View MongoDB Watchdog event page.
    $this->drupalGet(static::PATH_EVENT_BASE . $eventId);
    $session = $this->assertSession();
    $session->statusCodeEquals($statusCode);
    // MongoDB watchdog event page was displayed.
    if ($statusCode == Response::HTTP_OK) {
      $expectedTexts = [
        'Event template',
        'ID',
        'Changed',
        'Count',
        'Type',
        'Message',
        'Severity',
        $eventId,
        'Event occurrences',
        $expectedMessage,
      ];
      foreach ($expectedTexts as $expectedText) {
        $session->pageTextContains($expectedText);
      }
    }
  }

  /**
   * The access and contents of the admin/reports/mongodb/watchdog[/*] pages.
   *
   * @TODO verifyRowLimit(), verifyCron(), verifyEvents() as per DbLog.
   */
  public function testLoggerReportsAccess() {
    $expectations = [
      [$this->adminUser, Response::HTTP_OK],
      [$this->bigUser, Response::HTTP_OK],
      [$this->anyUser, Response::HTTP_FORBIDDEN],
    ];
    /** @var \Drupal\user\Entity\User $account */
    foreach ($expectations as $expectation) {
      [$account, $statusCode] = $expectation;
      $this->drupalLogin($account);
      $this->verifyReports($statusCode);
    }
  }

  /**
   * Test the UI clearing feature.
   */
  public function testLoggerAddAndUiClear() {
    // Drop the logger database to ensure no collections.
    $this->container->get(MongoDb::SERVICE_DB_FACTORY)
      ->get(Logger::DB_LOGGER)
      ->drop();

    /** @var \Drupal\Core\Logger\LoggerChannelInterface $loggerChannel */
    $loggerChannel = $this->container->get(Logger::SERVICE_CHANNEL);
    // Add a watchdog entry. Be sure not to include placeholder delimiters.
    $message = static::neuter($this->randomString(32));
    $loggerChannel->notice($message);

    // Make sure the collections were updated.
    /** @var \Drupal\mongodb_watchdog\Logger $logger */
    $logger = $this->container->get(Logger::SERVICE_LOGGER);
    $templates = $logger->templateCollection();
    $this->assertEquals(1, $templates->countDocuments(),
      'Logging created templates collection and added a template to it.');

    $template = $templates->findOne(['message' => $message], MongoDb::ID_PROJECTION);
    $this->assertNotNull($template, "Logged message was found: [${message}]");
    $templateId = $template['_id'];
    $events = $logger->eventCollection($templateId);
    $this->assertEquals(1, $events->countDocuments(),
      'Logging created events collection and added a template to it.');

    // Login the admin user.
    $this->drupalLogin($this->adminUser);
    // Now post to clear the db table.
    $this->drupalGet('admin/reports/mongodb/confirm');
    $this->submitForm([], 'Confirm');

    // Make the sure logs were dropped. After a UI clear, the templates
    // collection should exist, since it is recreated as a capped collection as
    // part of the clear, but be empty, and there should be no event collection.
    $count = $templates->countDocuments();
    $failMessage = 'Logger templates collection was cleared';
    if ($count > 0) {
      $options = ['projection' => ['_id' => 0, 'message' => 1]];
      $messages = iterator_to_array($templates->find([], $options));
      $failMessage = "Logger templates collection still contains messages: "
        . json_encode($messages);
    }
    $this->assertEquals(0, $count, $failMessage);
    $this->assertFalse($logger->eventCollections()->valid(),
      "Event collections were dropped");
  }

  /**
   * Test the dblog filter on admin/reports/dblog.
   */
  public function testFilter() {
    $this->drupalLogin($this->bigUser);

    // Clear log to ensure that only generated entries are found.
    $database = $this->container
      ->get(MongoDb::SERVICE_DB_FACTORY)
      ->get(Logger::DB_LOGGER);
    $database->drop();

    $logger = $this->container->get(Logger::SERVICE_LOGGER);

    // Generate watchdog entries.
    $typeNames = [];
    $types = [];
    for ($i = 0; $i < 3; $i++) {
      $typeNames[] = $typeName = $this->randomMachineName();
      $severity = RfcLogLevel::EMERGENCY;
      for ($j = 0; $j < 3; $j++) {
        $types[] = $type = [
          'count' => mt_rand(1, 5),
          'type' => $typeName,
          'severity' => $severity++,
        ];
        $this->insertLogEntries($logger, $type['count'], $type['type'], $type['severity']);
      }
    }
    // View the dblog.
    $this->drupalGet(self::PATH_OVERVIEW);

    // Confirm all the entries are displayed.
    $this->assertTypeCount($types);

    // Filter by each type and confirm that entries with various severities are
    // displayed.
    foreach ($typeNames as $typeName) {
      $edit = [
        'type[]' => [$typeName],
      ];
      $this->submitForm($edit, 'Filter');

      // Check whether the displayed event templates match our filter.
      $filteredTypes = array_filter($types, function (array $type) use ($typeName) {
        return $type['type'] === $typeName;
      });
      $this->assertTypeCount($filteredTypes);
    }

    // Set filter to match each of the combined filter sets and confirm the
    // entries displayed.
    foreach ($types as $type) {
      $edit = [
        'type[]' => $typeType = $type['type'],
        'severity[]' => $typeSeverity = $type['severity'],
      ];
      $this->submitForm($edit, 'Filter');

      $filteredTypes = array_filter($types, function (array $type) use ($typeType, $typeSeverity) {
        return $type['type'] === $typeType && $type['severity'] == $typeSeverity;
      });

      $this->assertTypeCount($filteredTypes);
    }
  }

}
