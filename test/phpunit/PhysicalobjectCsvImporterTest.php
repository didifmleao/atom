<?php

use org\bovigo\vfs\vfsStream;

class PhysicalObjectCsvImporterTest extends \PHPUnit\Framework\TestCase
{
  protected $csvHeader;
  protected $csvData;
  protected $typeIdLookupTable;
  protected $ormPhysicalObjectClass;
  protected $ormInformationObjectClass;
  protected $vfs;               // virtual filesystem
  protected $vdbcon;            // virtual database connection


  /**************************************************************************
   * Fixtures
   **************************************************************************/

  public function setUp() : void
  {
    $this->context = sfContext::getInstance();
    $this->vdbcon = $this->createMock(DebugPDO::class);
    $this->ormPhysicalObjectClass = \AccessToMemory\test\mock\QubitPhysicalObject::class;
    $this->ormInformationObjectClass = \AccessToMemory\test\mock\QubitInformationObject::class;

    $this->csvHeader = 'name,type,location,culture,descriptionSlugs';

    $this->csvData = array(
      // Note: leading whitespace in " DJ001" is intentional
      '" DJ001", "Folder", "Aisle 25, Shelf D", "en", "test-fonds-1 | test-collection"',
      '"", "Chemise", "", "fr",""',
      '"DJ002", "Boîte Hollinger", "Voûte, étagère 0074", "fr", "Mixed-Case-Fonds|no-match|"'
    );

    $this->typeIdLookupTableFixture = [
      'en' => [
        'hollinger box' => 1,
        'folder' => 2,
      ],
      'fr' => [
        'boîte hollinger' => 1,
        'chemise' => 2,
      ]
    ];

    // define virtual file system
    $directory = [
      'test' => [
        'unix.csv' => $this->csvHeader."\n".implode("\n", $this->csvData),
        'windows.csv' => $this->csvHeader."\r\n".implode("\r\n", $this->csvData)
          ."\r\n",
        'noheader.csv' => implode("\n", $this->csvData)."\n",
        'invalid.csv' => 'containerName,'."\n".implode("\n", $this->csvData),
        'root.csv' => $this->csvData[0],
      ]
    ];

    // setup and cache the virtual file system
    $this->vfs = vfsStream::setup('root', null, $directory);

    // Make 'root.csv' owned and readable only by root user
    $file = $this->vfs->getChild('root/test/root.csv');
    $file->chmod('0400');
    $file->chown(vfsStream::OWNER_ROOT);
  }


  /**************************************************************************
   * Data providers
   **************************************************************************/

  public function setOptionsProvider()
  {
    return [
      [
        ['option1' => 'value1'],
        ['option1' => 'value1'],
      ],
      [
        ['option1' => 'value1', 'option2' => 'value2'],
        ['option1' => 'value1', 'option2' => 'value2'],
      ],
      [null, null],
      [[], null],
    ];
  }

  public function processRowProvider()
  {
    $inputs = [
      // Leading and trailing whitespace is intentional
      [
        'name'             => ' DJ001',
        'type'             => 'Boîte Hollinger ',
        'location'         => ' Voûte, étagère 0074',
        'culture'          => 'fr ',
        'descriptionSlugs' => ' test-fonds-1 | test-collection ',
      ],
      [
        'name'             => 'DJ002 ',
        'type'             => 'Folder',
        'location'         => 'Aisle 25, Shelf D',
        // Test case insensitivity (should match 'en')
        'culture'          => 'EN',
        // Slugs are case sensitive
        'descriptionSlugs' => 'Mixed-Case-Fonds|no-match|',
      ],
      [
        'name'             => 'DJ003',
        'type'             => '',
        'location'         => '',
        'culture'          => '',
        'descriptionSlugs' => ''
      ],
    ];

    $expectedResults = [
      [
        'name'                 => 'DJ001',
        'typeId'               => 1,
        'location'             => 'Voûte, étagère 0074',
        'culture'              => 'fr',
        'informationObjectIds' => [111111, 222222],
      ],
      [
        'name'                 => 'DJ002',
        'typeId'               => 2,
        'location'             => 'Aisle 25, Shelf D',
        'culture'              => 'en',
        'informationObjectIds' => [333333],
      ],
      [
        'name'                 => 'DJ003',
        'typeId'               => null,
        'location'             => '',
        'culture'              => 'en',
        'informationObjectIds' => [],
      ],
    ];

    return [
      [$inputs[0], $expectedResults[0]],
      [$inputs[1], $expectedResults[1]],
      [$inputs[2], $expectedResults[2]],
    ];
  }


  /**************************************************************************
   * Tests
   **************************************************************************/

  public function testConstructorWithNoContextPassed()
  {
    $importer = new PhysicalObjectCsvImporter(null, $this->vdbcon);

    $this->assertSame(sfContext::class, get_class($importer->context));
  }

  public function testConstructorWithNoDbconPassed()
  {
    $importer = new PhysicalObjectCsvImporter($this->context, null);

    $this->assertSame(DebugPDO::class, get_class($importer->dbcon));
  }

  public function testMagicGetInvalidPropertyException()
  {
    $this->expectException(sfException::class);
    $importer = new PhysicalObjectCsvImporter($this->context, $this->vdbcon);
    $foo = $importer->blah;
  }

  public function testMagicSetInvalidPropertyException()
  {
    $this->expectException(sfException::class);
    $importer = new PhysicalObjectCsvImporter($this->context, $this->vdbcon);
    $importer->foo = 'blah';
  }

  public function testSetAndGetMultiValueDelimiter()
  {
    $importer = new PhysicalObjectCsvImporter($this->context, $this->vdbcon);
    $importer->multiValueDelimiter = '/';
    $this->assertSame('/', $importer->multiValueDelimiter);
  }

  public function testSetFilenameFileNotFoundException()
  {
    $this->expectException(sfException::class);
    $importer = new PhysicalObjectCsvImporter($this->context, $this->vdbcon);
    $importer->setFilename('bad_name.csv');
  }

  public function testSetFilenameFileUnreadableException()
  {
    $this->expectException(sfException::class);
    $importer = new PhysicalObjectCsvImporter($this->context, $this->vdbcon);
    $importer->setFilename($this->vfs->url().'/test/root.csv');
  }

  public function testSetFilenameSuccess()
  {
    // Explicit method call
    $importer = new PhysicalObjectCsvImporter($this->context, $this->vdbcon);
    $importer->setFilename($this->vfs->url().'/test/unix.csv');
    $this->assertSame($importer->filename, $this->vfs->url().'/test/unix.csv');

    // Magic __set
    $importer = new PhysicalObjectCsvImporter($this->context, $this->vdbcon);
    $importer->filename = $this->vfs->url().'/test/unix.csv';
    $this->assertSame($importer->filename, $this->vfs->url().'/test/unix.csv');
  }

  /**
   * @dataProvider setOptionsProvider
   */
  public function testSetOptions($options, $expected)
  {
    // direct method call
    $importer = new PhysicalObjectCsvImporter($this->context, $this->vdbcon);
    $importer->setOptions($options);
    $this->assertSame($expected, $importer->options);

    // magic __set()
    $importer = new PhysicalObjectCsvImporter($this->context, $this->vdbcon);
    $importer->options = $options;
    $this->assertSame($expected, $importer->options);
  }

  public function testSetOptionsThrowsInvalidArgumentException()
  {
    $this->expectException(InvalidArgumentException::class);

    $importer = new PhysicalObjectCsvImporter($this->context, $this->vdbcon);
    $importer->setOptions(1);
    $importer->setOptions(new stdClass);
  }

  public function testSetIndexOnLoad()
  {
    $importer = new PhysicalObjectCsvImporter($this->context, $this->vdbcon);

    $importer->setIndexOnLoad(true);
    $this->assertSame(true, $importer->indexOnLoad);

    // Use magic __set()
    $importer->indexOnLoad = false;
    $this->assertSame(false, $importer->indexOnLoad);

    // Test boolean casting
    $importer->setIndexOnLoad(1);
    $this->assertSame(true, $importer->indexOnLoad);

    $importer->setIndexOnLoad(null);
    $this->assertSame(false, $importer->indexOnLoad);
  }

  public function testSetIndexOnLoadFromOptions()
  {
    $importer = new PhysicalObjectCsvImporter($this->context, $this->vdbcon);
    $importer->setOptions(array('indexOnLoad' => true));

    $this->assertSame(true, $importer->indexOnLoad);
  }

  public function testDoImportNoFilenameException()
  {
    $this->expectException(sfException::class);

    $importer = new PhysicalObjectCsvImporter($this->context, $this->vdbcon);
    $importer->doImport();
  }

  public function testDoImportSetsHeader()
  {
    $importer = new PhysicalObjectCsvImporter($this->context, $this->vdbcon);

    $importer->typeIdLookupTable = $this->typeIdLookupTableFixture;
    $importer->ormPhysicalObjectClass = $this->ormPhysicalObjectClass;
    $importer->ormInformationObjectClass = $this->ormInformationObjectClass;

    $importer->doImport($this->vfs->url().'/test/unix.csv');

    $this->assertSame(explode(',', $this->csvHeader), $importer->header);
  }

  public function testGetHeaderReturnsNullBeforeImport()
  {
    $importer = new PhysicalObjectCsvImporter($this->context, $this->vdbcon);

    $this->assertSame(null, $importer->getHeader());
  }

  /**
   * @dataProvider processRowProvider
   */
  public function testProcessRow($data, $expectedResult)
  {
    $importer = new PhysicalObjectCsvImporter($this->context, $this->vdbcon,
      ['defaultCulture' => 'en']);
    $importer->typeIdLookupTable = $this->typeIdLookupTableFixture;
    $importer->ormInformationObjectClass = $this->ormInformationObjectClass;

    $result = $importer->processRow($data);

    // assertSame returns an error if array order is no the same
    ksort($expectedResult);
    ksort($result);

    $this->assertSame($expectedResult, $result);
  }

  public function testProcessRowThrowsExceptionIfNoNameOrLocation()
  {
    $this->expectException(UnexpectedValueException::class);

    $importer = new PhysicalObjectCsvImporter($this->context, $this->vdbcon);
    $importer->typeIdLookupTable = $this->typeIdLookupTableFixture;

    $importer->processRow([
        'name'     => '',
        'type'     => 'Boîte Hollinger',
        'location' => '',
        'culture'  => 'fr'
      ], 0);
  }

  public function testProcessRowThrowsExceptionIfUnknownType()
  {
    $this->expectException(UnexpectedValueException::class);

    $importer = new PhysicalObjectCsvImporter($this->context, $this->vdbcon);
    $importer->typeIdLookupTable = $this->typeIdLookupTableFixture;

    $importer->processRow([
        'name'     => 'MPATHG',
        'type'     => 'Spam',
        'location' => 'Camelot',
        'culture'  => 'en'
      ], 0);
  }

  public function testGetRecordCulture()
  {
    $importer = new PhysicalObjectCsvImporter(
      $this->context, $this->vdbcon, array('defaultCulture' => 'de'));

    // Passed direct value
    $this->assertSame('fr', $importer->getRecordCulture('fr'));

    // Get culture from $this->defaultCulture
    $this->assertSame('de', $importer->getRecordCulture());

    // Get culture from sfConfig
    sfConfig::set('default_culture', 'en');
    $importer = new PhysicalObjectCsvImporter($this->context, $this->vdbcon);
    $this->assertSame('en', $importer->getRecordCulture());
  }

  public function testGetRecordCultureThrowsExceptionWhenCantDetermineCulture()
  {
    $this->expectException(UnexpectedValueException::class);

    sfConfig::set('default_culture', '');

    $importer = new PhysicalObjectCsvImporter($this->context, $this->vdbcon);
    $importer->getRecordCulture();
  }

  public function testTypeIdLookupTableSetAndGet()
  {
    $importer = new PhysicalObjectCsvImporter($this->context, $this->vdbcon);
    $importer->typeIdLookupTable = $this->typeIdLookupTableFixture;

    $this->assertSame($this->typeIdLookupTableFixture,
      $importer->typeIdLookupTable);
  }

  public function testGetTypeIdLookupTable()
  {
    $stub = $this->createStub(QubitTaxonomy::class);
    $stub->method('getTermIdLookupTable')
         ->willReturn($this->typeIdLookupTableFixture);

    $importer = new PhysicalObjectCsvImporter($this->context, $this->vdbcon);
    $importer->physicalObjectTypeTaxonomy = $stub;

    $this->assertEquals($this->typeIdLookupTableFixture,
      $importer->typeIdLookupTable);
  }

  public function testGetTypeIdLookupTableExceptionGettingTerms()
  {
    $stub = $this->createStub(QubitTaxonomy::class);
    $stub->method('getTermIdLookupTable')
         ->willReturn(null);

    $importer = new PhysicalObjectCsvImporter($this->context, $this->vdbcon);
    $importer->physicalObjectTypeTaxonomy = $stub;

    $this->expectException(sfException::class);
    $importer->typeIdLookupTable;
  }

  public function testGetPhysicalObjectTypeTaxonomy()
  {
    $stub = $this->createStub(QubitTaxonomy::class);

    $importer = new PhysicalObjectCsvImporter($this->context, $this->vdbcon);
    $importer->physicalObjectTypeTaxonomy = $stub;

    $this->assertSame($stub, $importer->physicalObjectTypeTaxonomy);
  }
}
