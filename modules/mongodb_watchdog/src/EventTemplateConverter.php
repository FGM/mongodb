<?php

declare(strict_types = 1);

namespace Drupal\mongodb_watchdog;

use Drupal\Core\ParamConverter\ParamConverterInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Route;

/**
 * Class EventTemplateConverter load MongoDB watchdog event templates by id.
 */
class EventTemplateConverter implements ParamConverterInterface {
  const PARAM_TYPE = 'mongodb_watchdog_event_template';

  /**
   * The core logger channel service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The MongoDB logger service, to load events.
   *
   * @var \Drupal\mongodb_watchdog\Logger
   */
  protected $watchdog;

  /**
   * EventTemplateConverter constructor.
   *
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger service, to log intervening events.
   * @param \Drupal\mongodb_watchdog\Logger $watchdog
   *   The MongoDB logger, to load event templates.
   */
  public function __construct(LoggerInterface $logger, Logger $watchdog) {
    $this->logger = $logger;
    $this->watchdog = $watchdog;
  }

  /**
   * {@inheritdoc}
   */
  public function convert($value, $definition, $name, array $defaults): ?EventTemplate {
    if (!is_string($value)) {
      $this->logger->notice('Non-string event template id: %id', [
        '%id' => var_export($value, TRUE),
      ]);
      return NULL;
    }

    $selector = [
      '_id' => $value,
    ];
    $options = [
      'typeMap' => [
        'array' => 'array',
        'document' => 'array',
        'root' => 'Drupal\mongodb_watchdog\EventTemplate',
      ],
    ];

    // Returns null if there is no match, as expected by ParamConverter.
    // Never returns an array as findOne() could, because of $options.
    $template = $this->watchdog->templateCollection()->findOne($selector, $options);
    if (empty($template)) {
      $this->logger->notice('Invalid event template id: %id', ['%id' => $value]);
    }
    assert(is_null($template) || $template instanceof EventTemplate);
    return $template;
  }

  /**
   * {@inheritdoc}
   */
  public function applies($definition, $name, Route $route): bool {
    return $definition['type'] === static::PARAM_TYPE;
  }

}
