<?php

declare(strict_types = 1);

namespace Drupal\mongodb_storage\Command;

// @codingStandardsIgnoreLine
use Drupal\Console\Annotations\DrupalCommand;
use Drupal\Console\Core\Command\ContainerAwareCommand;
use Drupal\mongodb_storage\Install\SqlImport;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Provides the commands.mongodb.storage.import_keyvalue console command.
 *
 * @DrupalCommand (
 *     extension="mongodb_storage",
 *     extensionType="module"
 * )
 */
class SqlImportCommand extends ContainerAwareCommand {

  /**
   * The mongodb.storage.sql_import service.
   *
   * @var \Drupal\mongodb_storage\Install\SqlImport
   */
  protected $sqlImport;

  /**
   * SqlImportCommand constructor.
   *
   * @param \Drupal\mongodb_storage\Install\SqlImport $sqlImport
   *   The mongodb.storage.sql_import service.
   */
  public function __construct(SqlImport $sqlImport) {
    parent::__construct();
    $this->sqlImport = $sqlImport;
  }

  /**
   * {@inheritdoc}
   */
  protected function configure() {
    $name = 'commands.mongodb.storage.import_keyvalue';

    $this
      ->setName('mongodb:storage:import_keyvalue')
      ->setDescription($this->trans("${name}.description"))
      ->setHelp($this->trans("${name}.help"))
      ->setAliases(['mdbsikv', 'most-ikv']);
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output) {
    $out = $this->sqlImport->import();
    $this->getIo()->writeln($out);
  }

}
