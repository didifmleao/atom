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
  const EXCEPTION_ERROR_CODE = 0;
  const EXCEPTION_WARNING_CODE = 1;

  protected $context;
  protected $data;
  protected $dbcon;
  protected $filename;
  protected $indexOnLoad = false;
  protected $multiValueDelimiter = '|';
  protected $options;
  protected $ormInformationObjectClass = QubitInformationObject::class;
  protected $ormPhysicalObjectClass    = QubitPhysicalObject::class;
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
      case 'ormPhysicalObjectClass':
      case 'ormInformationObjectClass':
      case 'physicalObjectTypeTaxonomy':
      case 'typeIdLookupTable':
        $this->$name = $value;

        break;

      default:
        throw new sfException("Couldn't set unknown property \"$name\"");
    }
  }

  public function setFilename($filename)
  {
    $this->filename = $this->validateFilename($filename);
  }

  public function validateFilename($filename)
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
        if ($e->getCode() === self::EXCEPTION_ERROR_CODE)
        {
          $this->logError(sprintf('Skipping row [%u/%u]: %s',
            $offset, $csvRows, $e->getMessage()));

          continue;
        }

        $this->logError(sprintf('Warning on row [%u/%u]: %s',
            $offset, $csvRows, $e->getMessage()));
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

  public function processRow($data)
  {
    if (0 == strlen($data['name']) && 0 == strlen($data['location']))
    {
      throw new UnexpectedValueException('No name or location defined');
    }

    $prow = array();
    $prow['culture'] = $this->getRecordCulture($data['culture']);

    foreach ($data as $key => $val)
    {
      $this->processColumn($prow, $key, $val);
    }

    return $prow;
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

  public function getPhysicalObjectTypeTaxonomy()
  {
    if (null === $this->physicalObjectTypeTaxonomy)
    {
      // @codeCoverageIgnoreStart
      $this->physicalObjectTypeTaxonomy = QubitTaxonomy::getById(
        QubitTaxonomy::PHYSICAL_OBJECT_TYPE_ID,
        array('connection' => $this->getDbConnection())
      );
      // @codeCoverageIgnoreEnd
    }

    return $this->physicalObjectTypeTaxonomy;
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

  protected function processColumn(&$prow, $key, $val)
  {
    switch ($key)
    {
      case 'name':
      case 'location':
        $prow[$key] = trim($val);

        break;

      case 'type':
        $prow['typeId'] = $this->lookupTypeId($val, $prow['culture']);

        break;

      case 'descriptionSlugs':
        $prow['informationObjectIds'] = $this->processDescriptionSlugs($val);

        break;
    }
  }

  protected function processDescriptionSlugs(String $str)
  {
    $ids = array();

    foreach ($this->processMultiValueColumn($str) as $val)
    {
      $infobj = $this->ormInformationObjectClass::getBySlug($val);

      if (null === $infobj)
      {
        throw new UnexpectedValueException(
          sprintf('Couldn\'t find a description with slug "%s".', $val),
          self::EXCEPTION_WARNING_CODE
        );

        continue;
      }

      $ids[] = $infobj->id;
    }

    return $ids;
  }

  protected function processMultiValueColumn(String $str)
  {
    if ('' === trim($str))
    {
      return [];
    }

    $values = explode($this->multiValueDelimiter, $str);
    $values = array_map('trim', $values);

    // Remove empty strings from array
    $values = array_filter($values, function ($val) {
      return null !== $val && '' !== $val;
    });

    return $values;
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
    if (null === $this->typeIdLookupTable)
    {
      $this->typeIdLookupTable = $this
        ->getPhysicalObjectTypeTaxonomy()
        ->getTermIdLookupTable($this->getDbConnection());

      if (null === $this->typeIdLookupTable)
      {
        throw new sfException(
          'Couldn\'t load Physical object type terms from database');
      }
    }

    return $this->typeIdLookupTable;
  }

  /**
   * @codeCoverageIgnore
   */
  protected function writeRecordToDatabase($data)
  {
    $record = new $this->ormPhysicalObjectClass;
    $record->name     = $data['name'];
    $record->typeId   = $data['typeId'];
    $record->location = $data['location'];
    $record->culture  = $data['culture'];

    return $record->save($this->dbcon);
  }
}
