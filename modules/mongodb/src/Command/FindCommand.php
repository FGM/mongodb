<?php

declare(strict_types = 1);

namespace Drupal\mongodb\Command;

use Drupal\Component\Serialization\SerializationInterface;
use Drupal\mongodb\Install\Tools;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Drupal\Console\Core\Command\ContainerAwareCommand;
// @codingStandardsIgnoreLine
use Drupal\Console\Annotations\DrupalCommand;

/**
 * Class FindCommand provides the 'commands.mongodb.find' command.
 *
 * @DrupalCommand (
 *     extension="mongodb",
 *     extensionType="module"
 * )
 */
class FindCommand extends ContainerAwareCommand {

  const NAME = 'commands.mongodb.find';

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
   * FindCommand constructor.
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
    $arguments = "${name}.arguments";

    $usageGeneric = <<<USAGE
<collection> <query>
    <query> is a single JSON selector in single string format. Quote it.
USAGE;

    $usageNoSelector = <<<USAGE
logger watchdog
    Get the logger/watchdog error-level templates
USAGE;

    $usageStringInt = <<<USAGE
logger watchdog '{ "severity": 3 }'
    Get all the logger/watchdog entries tracking rows.
USAGE;

    $usageTwoStrings = <<<USAGE
keyvalue kvp_state '{ "_id": "system.theme_engine.files" }'
    Get a specific State entry. Note how escaping needs to be performed in the shell.
USAGE;

    $this
      ->setName('mongodb:find')
      ->setAliases(['mdbf', 'mo-find'])
      ->setDescription($this->trans("${name}.description"))
      ->setHelp($this->trans("${name}.help"))
      ->addUsage($usageGeneric)
      ->addUsage($usageNoSelector)
      ->addUsage($usageStringInt)
      ->addUsage($usageTwoStrings)
      ->addArgument('alias', InputArgument::REQUIRED, "${arguments}.alias")
      ->addArgument('collection', InputArgument::REQUIRED, "${arguments}.collection")
      ->addArgument('selector', InputArgument::OPTIONAL, "${arguments}.selector", '{ }');
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output) {
    // PHP 7.1 list('alias' => $alias ...) syntax is no yet supported in PHPMD.
    $arguments = $input->getArguments();
    // These are declared arguments, so they're known to have a value.
    $alias = $arguments['alias'];
    $collection = $arguments['collection'];
    $selector = $arguments['selector'];
    $this
      ->getIo()
      ->write(
        $this->serialization->encode(
          $this->tools->find($alias, $collection, $selector)
        )
      );
  }

}
