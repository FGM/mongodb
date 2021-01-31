<?php

declare(strict_types = 1);

namespace Drupal\mongodb_watchdog\Command;

use Drupal\Component\Serialization\Yaml;
// @codingStandardsIgnoreLine
use Drupal\Console\Annotations\DrupalCommand;
use Drupal\Console\Core\Command\ContainerAwareCommand;
use Drupal\mongodb_watchdog\Install\SanityCheck;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class SanityCheckCommand provides the mongodb:watchdog:sanitycheck command.
 *
 * @DrupalCommand (
 *     extension="mongodb_watchdog",
 *     extensionType="module"
 * )
 */
class SanityCheckCommand extends ContainerAwareCommand {

  /**
   * The mongodb.watchdog.sanity_check service.
   *
   * @var \Drupal\mongodb_watchdog\Install\SanityCheck
   */
  protected $sanityCheck;

  /**
   * The serialisation.yaml service.
   *
   * @var \Drupal\Component\Serialization\Yaml
   */
  protected $yaml;

  /**
   * SanityCheckCommand constructor.
   *
   * @param \Drupal\mongodb_watchdog\Install\Sanitycheck $sanityCheck
   *   The mongodb.watchdog.sanity_check service.
   * @param \Drupal\Component\Serialization\Yaml $yaml
   *   The serialization.yaml service.
   */
  public function __construct(Sanitycheck $sanityCheck, Yaml $yaml) {
    parent::__construct();
    $this->sanityCheck = $sanityCheck;
    $this->yaml = $yaml;
  }

  /**
   * {@inheritdoc}
   */
  protected function configure(): void {
    $this
      ->setName('mongodb:watchdog:sanitycheck')
      ->setDescription($this->trans('commands.mongodb.watchdog.sanitycheck.description'))
      ->setHelp($this->trans('commands.mongodb.watchdog.sanitycheck.help'))
      ->setAliases(['mdbwsc', 'mowd-sc']);
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output): void {
    $buckets = $this->sanityCheck->buildCollectionstats();
    $this->getIo()->writeln($this->yaml->encode($buckets));
  }

}
