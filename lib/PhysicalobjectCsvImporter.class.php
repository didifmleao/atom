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

use League\Csv\Reader;

/**
 * Importer for Physical Object CSV data
 *
 * @package    AccessToMemory
 * @subpackage PhysicalObject
 * @author     David Juhasz <djuhasz@artefactual.com>
 */
class PhysicalObjectCsvImporter
{
  protected $context;
  protected $data;
  protected $dbcon;
  protected $filename;
  protected $indexOnLoad = false;
  protected $multiValueDelimiter = '|';
  protected $options;
  protected $ormClass = QubitPhysicalObject::class;
  protected $reader;
  protected $typeIdLookupTable;
  protected $physicalObjectTypeTaxonomy;

  public function __construct(sfContext $context = null, $dbcon = null,
    $options = array())
  {
    if (null === $context)
    {
      $context = new sfContext(ProjectConfiguration::getActive());
    }

    $this->context = $context;
    $this->dbcon   = $dbcon;
    $this->setOptions($options);
  }

  public function __get($name)
  {
    switch ($name)
    {
      case 'context':
      case 'filename':
      case 'indexOnLoad':
      case 'multiValueDelimiter':
      case 'options':
        return $this->$name;

        break;

      case 'dbcon':
        return $this->getDbConnection();

        break;

      case 'header':
        return $this->getHeader();

        break;

      case 'physicalObjectTypeTaxonomy':
        return $this->getPhysicalObjectTypeTaxonomy();

        break;

      case 'typeIdLookupTable':
        return $this->getTypeIdLookupTable();

        break;

      default:
        throw new sfException("Unknown or inaccessible property \"$name\"");
    }
  }

  public function __set($name, $value)
  {
    switch ($name)
    {
      case 'filename':
        $this->setFilename($value);

        break;

      case 'options':
        $this->setOptions($value);

        break;

      case 'indexOnLoad':
        $this->setIndexOnLoad($value);

        break;

      case 'dbcon':
      case 'multiValueDelimiter':
      case 'ormClass':
      case 'physicalObjectTypeTaxonomy':
      case 'typeIdLookupTable':
        $this->$name = $value;

        break;

      default:
        throw new sfException("Couldn't set unknown property \"$name\"");
    }
  }

  protected function log($msg)
  {
    // Just echo to STDOUT for now
    echo $msg.PHP_EOL;
  }

  protected function logError($msg)
  {
    // @TODO: implement file based error log
    echo $msg.PHP_EOL;
  }

  protected function getDbConnection()
  {
    if (null === $this->dbcon)
    {
      $this->dbcon = Propel::getConnection();
    }

    return $this->dbcon;
  }

  public function setFilename($filename)
  {
    $this->filename = $this->validateFilename($filename);
  }

  protected function validateFilename($filename)
  {
    if (!file_exists($filename))
    {
      throw new sfException("Can not find file $filename");
    }

    if (!is_readable($filename))
    {
      throw new sfException("Can not read $filename");
    }

    return $filename;
  }

  public function setOptions($options)
  {
    if (null !== $options && !is_array($options))
    {
      throw new InvalidArgumentException(
        'Expected $options type is array or null.');
    }

    if (null === $options || 0 == count($options))
    {
      $this->options = null;
    }
    else
    {
      $this->options = $options;
    }

    if (isset($this->options['indexOnLoad']))
    {
      $this->indexOnLoad = $this->options['indexOnLoad'];
      unset($this->options['indexOnLoad']);
    }
  }

  public function setIndexOnLoad($value)
  {
    $this->indexOnLoad = (bool) $value;
  }

  public function doImport($filename = null)
  {
    if (null !== $filename)
    {
      $this->setFilename($filename);
    }

    if (null === $this->filename)
    {
      $msg = <<<EOL
Please use setFilename(\$filename) or doImport(\$filename) to specify the CSV
file you wish to import.
EOL;
      throw new sfException($msg);
    }

    $this->log(sprintf(PHP_EOL.'Importing physical object data from %s...'.
      PHP_EOL, $this->filename));

    $records = $this->readCsvFile($this->filename);
    $csvRows = count($this->reader);

    foreach ($records as $offset => $record)
    {
      try
      {
        $data = $this->processRow($record);
        $this->writeRecordToDatabase($data);
      }
      catch (UnexpectedValueException $e)
      {
        $this->logError(sprintf('Skipping row [%u/%u]: %s',
          $offset, $csvRows, $e->getMessage()));

        continue;
      }

      $this->log(sprintf('Imported row [%u/%u]: name "%s"',
        $offset, $csvRows, $data['name']));
    }

    $this->log(PHP_EOL.'Import complete!');
  }

  public function readCsvFile($filename)
  {
    $this->reader = Reader::createFromPath($filename, 'r');
    $this->reader->setHeaderOffset(0);

    return $this->reader->getRecords();
  }

  public function getHeader()
  {
    if (null === $this->reader)
    {
      return null;
    }

    return $this->reader->getHeader();
  }

  public function processRow($record)
  {
    if (0 == strlen($record['name']) && 0 == strlen($record['location']))
    {
      throw new UnexpectedValueException('No name or location defined');
    }

    $processed = array();
    $processed['culture'] = $this->getRecordCulture($record['culture']);

    foreach ($record as $key => $val)
    {
      $this->processColumn($processed, $key, $val);
    }

    return $processed;
  }

  protected function processColumn(&$processed, $key, $val)
  {
    switch ($key)
    {
      case 'name':
      case 'location':
        $processed[$key] = trim($val);

        break;

      case 'type':
        $processed['typeId'] = $this->lookupTypeId($val, $processed['culture']);

        break;

      case 'descriptionSlugs':
        $processed[$key] = $this->parseMultiValueColumn($val);

        break;
    }
  }

  protected function parseMultiValueColumn(String $str)
  {
    if ('' === trim($str))
    {
      return [];
    }

    $values = explode($this->multiValueDelimiter, $str);

    return array_map('trim', $values);
  }

  public function getRecordCulture($culture = null)
  {
    $culture = trim($culture);

    if (!empty($culture))
    {
      return strtolower($culture);
    }

    if (!empty($this->options['defaultCulture']))
    {
      return strtolower($this->options['defaultCulture']);
    }

    if (!empty(sfConfig::get('default_culture')))
    {
      return strtolower(sfConfig::get('default_culture'));
    }

    throw new UnexpectedValueException('Couldn\'t determine row culture');
  }

  protected function lookupTypeId($name, $culture)
  {
    // Allow typeId to be null
    if ('' === trim($name))
    {
      return;
    }

    $lookupTable = $this->getTypeIdLookupTable();
    $name = trim(strtolower($name));
    $culture = trim(strtolower($culture));

    if (null === $typeId = $lookupTable[$culture][$name])
    {
      $msg = <<<EOL
Couldn't find physical object type "$name" for culture "$culture"
EOL;
      throw new UnexpectedValueException($msg);
    }

    return $typeId;
  }

  protected function getTypeIdLookupTable()
  {
    if (null !== $this->typeIdLookupTable)
    {
      return $this->typeIdLookupTable;
    }

    return $this->buildTypeIdLookupTable();
  }

  protected function buildTypeIdLookupTable()
  {
    $terms = $this->getPhysicalObjectTypeTaxonomy()->getTermLookupTable(
      $this->getDbConnection());

    if (!is_array($terms) || count($terms) == 0)
    {
      throw new sfException(
        'Error loading physical object term types from database');
    }

    foreach ($terms as $term)
    {
      // Trim and lowercase values for lookup
      $term = array_map(function ($str) {
        return trim(strtolower($str));
      }, $term);

      $this->typeIdLookupTable[$term['culture']][$term['name']] = $term['id'];
    }

    return $this->typeIdLookupTable;
  }

  public function getPhysicalObjectTypeTaxonomy()
  {
    if (isset($this->physicalObjectTypeTaxonomy))
    {
      return $this->physicalObjectTypeTaxonomy;
    }

    // @codeCoverageIgnoreStart
    return QubitTaxonomy::getById(QubitTaxonomy::PHYSICAL_OBJECT_TYPE_ID,
      array('connection' => $this->getDbConnection()));
    // @codeCoverageIgnoreEnd
  }

  /**
   * @codeCoverageIgnore
   */
  protected function writeRecordToDatabase($data)
  {
    $record = new $this->ormClass;
    $record->name     = $data['name'];
    $record->typeId   = $data['typeId'];
    $record->location = $data['location'];
    $record->culture  = $data['culture'];

    return $record->save($this->dbcon);
  }
}
