<?php

declare(strict_types = 1);

namespace Drupal\mongodb_watchdog\Controller;

use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Link;
use Drupal\Core\Logger\RfcLogLevel;
use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\mongodb_watchdog\Event;
use Drupal\mongodb_watchdog\Logger;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The controller for the request events page.
 */
class RequestController extends ControllerBase {

  /**
   * The core date.formatter service.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;

  /**
   * A RfcLogLevel instance, to avoid static access.
   *
   * @var \Drupal\Core\Logger\RfcLogLevel
   */
  protected $rfcLogLevel;

  /**
   * The length of the absolute path to the site root, in runes.
   *
   * @var int
   */
  protected $rootLength;

  /**
   * Controller constructor.
   *
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger service, to log intervening events.
   * @param \Drupal\mongodb_watchdog\Logger $watchdog
   *   The MongoDB logger, to load stored events.
   * @param \Drupal\Core\Config\ImmutableConfig $config
   *   The module configuration.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $dateFormatter
   *   The core date.formatter service.
   * @param \Drupal\Core\Pager\PagerManagerInterface $pagerManager
   *   The core pager.manager service.
   * @param \Drupal\Core\Logger\RfcLogLevel $rfcLogLevel
   *   A RfcLogLevel instance, to avoid static access.
   */
  public function __construct(
    LoggerInterface $logger,
    Logger $watchdog,
    ImmutableConfig $config,
    DateFormatterInterface $dateFormatter,
    PagerManagerInterface $pagerManager,
    RfcLogLevel $rfcLogLevel) {
    parent::__construct($logger, $watchdog, $pagerManager, $config);

    $this->dateFormatter = $dateFormatter;
    $this->rfcLogLevel = $rfcLogLevel;

    // Add terminal "/".
    $this->rootLength = mb_strlen(DRUPAL_ROOT) + 1;
  }

  /**
   * Controller.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   * @param string $uniqueId
   *   The unique request id from mod_unique_id. Unsafe.
   *
   * @return array
   *   A render array.
   */
  public function build(Request $request, string $uniqueId): array {
    if (!preg_match('/^[-\w@]+$/', $uniqueId)) {
      throw new NotFoundHttpException('Request ID is not well-formed.');
    }

    $events = $this->getRowData($request, $uniqueId);

    if (empty($events)) {
      $top = NULL;
      $main = $this->buildEmpty($this->t('No events found for this request.'));
    }
    else {
      [, $first] = reset($events);
      $top = $this->getTop($uniqueId, $first);
      $main = $this->buildMainTable($events);
    }

    $ret = $this->buildDefaults($main, $top);
    return $ret;
  }

  /**
   * Build the main table.
   *
   * @param array $rows
   *   The event data, as row entries for a "table" element.
   *
   * @return array
   *   A render array for the main table.
   */
  protected function buildMainTable(array $rows): array {
    $ret = [
      '#header' => $this->buildMainTableHeader(),
      '#rows' => $this->buildMainTableRows($rows),
      '#type' => 'table',
    ];
    return $ret;
  }

  /**
   * Build the main table header.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup[]
   *   A "table" element header array.
   */
  protected function buildMainTableHeader(): array {
    $header = [
      $this->t('Sequence'),
      $this->t('Type'),
      $this->t('Severity'),
      $this->t('Event'),
      $this->t('File'),
      $this->t('Line'),
    ];

    return $header;
  }

  /**
   * Build the main table rows.
   *
   * @param \Drupal\mongodb_watchdog\EventTemplate[]\Drupal\mongodb_watchdog\Event[] $events
   *   A fully loaded array of events and their templates.
   *
   * @return array
   *   A render array for a table.
   */
  protected function buildMainTableRows(array $events): array {
    $rows = [];
    $levels = $this->rfcLogLevel->getLevels();
    $event = NULL;
    $template = NULL;

    /** @var \Drupal\mongodb_watchdog\EventTemplate $template */
    /** @var \Drupal\mongodb_watchdog\Event $event */
    foreach ($events as [$template, $event]) {
      $row = [];
      $row[] = ['data' => $event->requestTracking_sequence];
      $row[] = $template->type;
      $row[] = [
        'data' => $levels[$template->severity],
        'class' => OverviewController::SEVERITY_CLASSES[$template->severity],
      ];
      $row[] = [
        'data' => Link::createFromRoute($template->asString($event->variables), 'mongodb_watchdog.reports.detail', [
          'eventTemplate' => $template->_id,
        ]),
      ];
      $row[] = $this->simplifyPath($event->variables['%file']);
      $row[] = $event->variables['%line'];
      $rows[] = $row;
    }

    return $rows;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    /** @var \Psr\Log\LoggerInterface $logger */
    $logger = $container->get('logger.channel.mongodb_watchdog');

    /** @var \Drupal\mongodb_watchdog\Logger $watchdog */
    $watchdog = $container->get(Logger::SERVICE_LOGGER);

    /** @var \Drupal\Core\Config\ImmutableConfig $config */
    $config = $container->get('config.factory')->get('mongodb_watchdog.settings');

    /** @var \Drupal\Core\Datetime\DateFormatterInterface $dateFormatter */
    $dateFormatter = $container->get('date.formatter');

    /** @var \Drupal\Core\Pager\PagerManagerInterface $pagerManager */
    $pagerManager = $container->get('pager.manager');

    $rfcLogLevel = new RfcLogLevel();

    return new static($logger, $watchdog, $config, $dateFormatter, $pagerManager, $rfcLogLevel);
  }

  /**
   * Obtain the data from the logger.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request. Needed for paging.
   * @param string $uniqueId
   *   The request for which to build the detail page.
   *
   * @return \Drupal\mongodb_watchdog\Event[]
   *   The data array.
   */
  protected function getRowData(Request $request, string $uniqueId): array {
    $count = $this->watchdog->requestEventsCount($uniqueId);
    $page = $this->setupPager($request, $count);
    $skip = $page * $this->itemsPerPage;
    $height = $this->itemsPerPage;

    $events = $this->watchdog->requestEvents($uniqueId, $skip, $height);
    return $events;
  }

  /**
   * Build the heading rows on the per-request event occurrences page.
   *
   * @param string $uniqueId
   *   The unique request id.
   * @param \Drupal\mongodb_watchdog\Event|null $first
   *   A fully loaded array of events and their templates.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup[]
   *   A render array for a table.
   */
  protected function getTop(string $uniqueId = "", ?Event $first = NULL): array {
    $timestamp = isset($first->timestamp)
      ? $this->dateFormatter->format($first->timestamp, 'long')
      : $this->t('No information');

    $rows = [
      [$this->t('Request ID'), $uniqueId],
      [$this->t('Location'), $first->location()],
      [$this->t('Date/time'), $timestamp],
    ];

    foreach ($rows as &$row) {
      $row[0] = [
        'data' => $row[0],
        'header' => TRUE,
      ];
    }

    $ret = [
      '#caption' => $this->t('Request'),
      '#rows' => $rows,
      '#type' => 'table',
    ];

    return $ret;
  }

  /**
   * Convert an absolute path to a relative one if below the site root.
   *
   * @param string $path
   *   An absolute path on the filesystem.
   *
   * @return string
   *   A relative path if possible, otherwise the input path.
   */
  public function simplifyPath(string $path) {
    $ret = (mb_strpos($path, DRUPAL_ROOT) === 0)
      ? mb_strpos($path, $this->rootLength)
      : $path;

    return $ret;
  }

}
