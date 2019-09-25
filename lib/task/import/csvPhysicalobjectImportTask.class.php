<?php

/*
 * This file is part of the Access to Memory (AtoM) software.
 *
 * Access to Memory (AtoM) is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Access to Memory (AtoM) is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Access to Memory (AtoM).  If not, see <http://www.gnu.org/licenses/>.
 */

/**
 * Import csv authoriy record data
 *
 * @package    AccessToMemory
 * @subpackage lib/task/csvImport
 * @author     David Juhasz <djuhasz@artefactual.com>
 */
class csvPhysicalobjectImportTask extends arBaseTask
{
  /**
   * @see sfBaseTask
   */
  protected function configure()
  {
    $this->addArguments(array(
      new sfCommandArgument('filename', sfCommandArgument::REQUIRED,
        'The input file name (csv format).')
    ));

    $this->addOptions(array(
      new sfCommandOption('application', null,
        sfCommandOption::PARAMETER_OPTIONAL, 'The application name', 'qubit'),
      new sfCommandOption('env', null, sfCommandOption::PARAMETER_REQUIRED,
        'The environment', 'cli'),
      new sfCommandOption('connection', null,
        sfCommandOption::PARAMETER_REQUIRED, 'The connection name', 'propel'),

      // Import options
      new sfCommandOption('index', null,
        sfCommandOption::PARAMETER_NONE, "Index for search during import."),
    ));

    $this->namespace = 'csv';
    $this->name = 'physicalobject-import';
    $this->briefDescription = 'Import physical object CSV data.';
    $this->detailedDescription = <<<EOF
      Import physical object CSV data
EOF;
  }

  /**
   * @see sfTask
   */
  public function execute($arguments = array(), $options = array())
  {
    parent::execute($arguments, $options);

    $importOptions = $this->setImportOptions($options);

    $importer = new PhysicalObjectCsvImporter(
      $this->context, $this->getDbConnection(), $importOptions);

    $importer->doImport($arguments['filename']);
  }

  protected function getDbConnection()
  {
    $databaseManager = new sfDatabaseManager($this->configuration);

    return $databaseManager->getDatabase('propel')->getConnection();
  }

  protected function setImportOptions($options)
  {
    $opts = array();

    // Update search index while importing data?
    $opts['indexOnLoad'] = isset($options['index']) ? true : false;

    return $opts;
  }
}
