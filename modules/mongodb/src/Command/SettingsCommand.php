<?php

declare(strict_types = 1);

namespace Drupal\mongodb\Command;

use Drupal\Component\Serialization\SerializationInterface;
use Drupal\mongodb\Install\Tools;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Drupal\Console\Core\Command\ContainerAwareCommand;
// @codingStandardsIgnoreLine
use Drupal\Console\Annotations\DrupalCommand;

/**
 * Class SettingsCommand provides the 'commands.mongodb.settings' command.
 *
 * @DrupalCommand (
 *     extension="mongodb",
 *     extensionType="module"
 * )
 */
class SettingsCommand extends ContainerAwareCommand {

  const NAME = 'commands.mongodb.settings';

  /**
   * The mongodb.tools service.
   *
   * @var \Drupal\mongodb\Install\Tools
   */
  protected $tools;

  /**
   * The serialization.yaml service.
   *
   * @var \Drupal\Component\Serialization\SerializationInterface
   */
  protected $serialization;

  /**
   * SettingsCommand constructor.
   *
   * @param \Drupal\mongodb\Install\Tools $tools
   *   The mongodb.tools service.
   * @param \Drupal\Component\Serialization\SerializationInterface $yaml
   *   The serialization.yaml service.
   */
  public function __construct(Tools $tools, SerializationInterface $yaml) {
    parent::__construct();
    $this->serialization = $yaml;
    $this->tools = $tools;
  }

  /**
   * {@inheritdoc}
   */
  protected function configure() {
    $name = static::NAME;

    $this
      ->setName('mongodb:settings')
      ->setAliases(['mdbs', 'mo-set'])
      ->setDescription($this->trans("${name}.description"))
      ->setHelp($this->trans("${name}.help"));
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output) {
    $this
      ->getIo()
      ->write(
        $this->serialization->encode(
          $this->tools->settings()
        )
      );
  }

}
