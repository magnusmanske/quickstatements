<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Testable subclass of QuickStatements that bypasses the constructor
 * (which requires external config files and database connections)
 * and exposes protected methods for unit testing.
 */
class TestableQuickStatements extends QuickStatements
{
    /**
     * Override constructor to avoid loading config.json and external dependencies.
     */
    public function __construct()
    {
        // Do NOT call parent::__construct() — it requires config.json,
        // magnustools, WikidataItemList, etc.

        $this->config = (object) [
            'site' => 'wikidata',
            'sites' => (object) [
                'wikidata' => (object) [
                    'types' => (object) [
                        'P' => (object) ['type' => 'property'],
                        'Q' => (object) ['type' => 'item'],
                        'L' => (object) ['type' => 'lexeme'],
                    ],
                    'entityBase' => 'http://www.wikidata.org/entity/',
                ],
            ],
        ];
    }

    // ---- Public wrappers around protected methods ----

    public function exposedParseValueV1(string $v, array &$cmd): ?bool
    {
        return $this->parseValueV1($v, $cmd);
    }

    public function exposedIsValidItemIdentifier(string $q): bool
    {
        return (bool) $this->isValidItemIdentifier($q);
    }

    public function exposedCompareDatavalue(object $d1, object $d2): bool
    {
        return $this->compareDatavalue($d1, $d2);
    }

    public function exposedGetEntityType(string $q): string
    {
        return $this->getEntityType($q);
    }

    public function exposedGetSnakType(object $datavalue): string
    {
        return $this->getSnakType($datavalue);
    }

    public function exposedGetSite(): object
    {
        $site = $this->config->site;
        return $this->config->sites->$site;
    }

    public function exposedImportDataFromV1(string $data): array
    {
        return $this->importData($data, 'v1');
    }

    public function exposedCompressCommands(array $commands): array
    {
        $this->use_command_compression = true;
        $result = $this->compressCommands($commands);
        $this->use_command_compression = false;
        return $result;
    }

    public function exposedIsLastKeyword(string $s): bool
    {
        return $this->isLastKeyword($s);
    }

    public function exposedEncodeLastState(): string
    {
        return $this->encodeLastState();
    }

    public function exposedDecodeLastState(string $stored): void
    {
        $this->decodeLastState($stored);
    }
}

/**
 * Further subclass that stubs out runAction so we can test command execution
 * logic (LAST propagation, routing, etc.) without hitting any real API.
 *
 * Every call to runAction marks the command as "done" and, for the create
 * actions, fakes the entity / form / sense ID that the API would return.
 */
class MockableQuickStatements extends TestableQuickStatements
{
    /** @var int Counter used to generate fake entity IDs. */
    private $nextId = 1;

    /** @var array Every params object passed to runAction, for inspection. */
    public $actionLog = [];

    public function __construct()
    {
        parent::__construct();
        // Provide a mock WikidataItemList so commands that go through the
        // loadItems → hasItem → getItem path don't hit null references.
        $this->wd = new class {
            public function loadItems($list) {}
            public function loadItem($q) {}
            public function hasItem($q) { return true; }
            public function getItem($q) {
                return new class {
                    public $j;
                    public function __construct() {
                        $this->j = (object) ['lastrevid' => 1];
                    }
                    public function getClaims($prop) { return []; }
                    public function getLabel($lang, $fallback = false) { return ''; }
                    public function getDesc($lang, $fallback = false) { return ''; }
                    public function getSitelink($site) { return null; }
                };
            }
            public function updateItem($q) {}
        };
    }

    protected function runAction($params, &$command)
    {
        $params = (object) $params;
        $this->actionLog[] = clone $params;

        // Simulate success
        $command->status = 'done';

        // Fake the entity ID that the real API would return
        if ($params->action == 'wbeditentity' && isset($params->new)) {
            $type = $params->new;
            if ($type == 'item') {
                $command->item = 'Q' . (9000 + $this->nextId++);
            } elseif ($type == 'property') {
                $command->item = 'P' . (9000 + $this->nextId++);
            } elseif ($type == 'lexeme') {
                $command->item = 'L' . (9000 + $this->nextId++);
            }
        }
        if ($params->action == 'wbladdform') {
            // Real API returns the form ID under $result->form->id;
            // runAction then sets $command->item.  We simulate that here.
            $lexemeId = $params->lexemeId;
            $command->item = $lexemeId . '-F' . $this->nextId++;
        }
        if ($params->action == 'wbladdsense') {
            $lexemeId = $params->lexemeId;
            $command->item = $lexemeId . '-S' . $this->nextId++;
        }
    }

    /**
     * Import V1, convert to objects, and run every command sequentially,
     * exactly like runCommandArray / runNextCommandInBatch would.
     *
     * @return object[] The executed command objects, in order.
     */
    public function importAndRun(string $v1): array
    {
        $result = $this->importData($v1, 'v1');
        $commands = $result['data']['commands'];
        $executed = [];
        foreach ($commands as $cmd) {
            $cmd = $this->array2object($cmd);
            $cmd = $this->runSingleCommand($cmd);
            $executed[] = $cmd;
        }
        return $executed;
    }

    public function getLastItem(): string
    {
        return $this->last_item;
    }

    public function getLastForm(): string
    {
        return $this->last_form;
    }

    public function getLastSense(): string
    {
        return $this->last_sense;
    }
}

/**
 * Unit tests for the QuickStatements class.
 *
 * These tests exercise pure / near-pure methods that do not require a
 * database connection, OAuth, or MediaWiki API access.
 *
 * @group unit
 */
class QuickStatementsTest extends TestCase
{
    /** @var TestableQuickStatements */
    private $qs;

    protected function setUp(): void
    {
        $this->qs = new TestableQuickStatements();
    }

    // =========================================================================
    //  1. parseValueV1()
    // =========================================================================

    /**
     * @group unit
     */
    public function testParseValueV1_ItemReference(): void
    {
        $cmd = [];
        $result = $this->qs->exposedParseValueV1('Q42', $cmd);

        $this->assertTrue($result);
        $this->assertSame('wikibase-entityid', $cmd['datavalue']['type']);
        $this->assertSame('item', $cmd['datavalue']['value']['entity-type']);
        $this->assertSame('Q42', $cmd['datavalue']['value']['id']);
    }

    /**
     * @group unit
     */
    public function testParseValueV1_PropertyReference(): void
    {
        $cmd = [];
        $result = $this->qs->exposedParseValueV1('P31', $cmd);

        $this->assertTrue($result);
        $this->assertSame('wikibase-entityid', $cmd['datavalue']['type']);
        $this->assertSame('property', $cmd['datavalue']['value']['entity-type']);
        $this->assertSame('P31', $cmd['datavalue']['value']['id']);
    }

    /**
     * @group unit
     */
    public function testParseValueV1_LexemeReference(): void
    {
        $cmd = [];
        $result = $this->qs->exposedParseValueV1('L123', $cmd);

        $this->assertTrue($result);
        $this->assertSame('wikibase-entityid', $cmd['datavalue']['type']);
        $this->assertSame('lexeme', $cmd['datavalue']['value']['entity-type']);
        $this->assertSame('L123', $cmd['datavalue']['value']['id']);
    }

    /**
     * @group unit
     */
    public function testParseValueV1_LexemeForm(): void
    {
        $cmd = [];
        $result = $this->qs->exposedParseValueV1('L123-F1', $cmd);

        $this->assertTrue($result);
        $this->assertSame('wikibase-entityid', $cmd['datavalue']['type']);
        $this->assertSame('form', $cmd['datavalue']['value']['entity-type']);
        $this->assertSame('L123-F1', $cmd['datavalue']['value']['id']);
    }

    /**
     * @group unit
     */
    public function testParseValueV1_LexemeSense(): void
    {
        $cmd = [];
        $result = $this->qs->exposedParseValueV1('L123-S1', $cmd);

        $this->assertTrue($result);
        $this->assertSame('wikibase-entityid', $cmd['datavalue']['type']);
        $this->assertSame('sense', $cmd['datavalue']['value']['entity-type']);
        $this->assertSame('L123-S1', $cmd['datavalue']['value']['id']);
    }

    /**
     * @group unit
     */
    public function testParseValueV1_StringValue(): void
    {
        $cmd = [];
        $result = $this->qs->exposedParseValueV1('"Hello World"', $cmd);

        $this->assertTrue($result);
        $this->assertSame('string', $cmd['datavalue']['type']);
        $this->assertSame('Hello World', $cmd['datavalue']['value']);
    }

    /**
     * @group unit
     */
    public function testParseValueV1_StringValueWithWhitespace(): void
    {
        $cmd = [];
        $result = $this->qs->exposedParseValueV1('"  padded  "', $cmd);

        $this->assertTrue($result);
        $this->assertSame('string', $cmd['datavalue']['type']);
        $this->assertSame('padded', $cmd['datavalue']['value']);
    }

    /**
     * @group unit
     */
    public function testParseValueV1_MonolingualText(): void
    {
        $cmd = [];
        $result = $this->qs->exposedParseValueV1('en:"Hello"', $cmd);

        $this->assertTrue($result);
        $this->assertSame('monolingualtext', $cmd['datavalue']['type']);
        $this->assertSame('en', $cmd['datavalue']['value']['language']);
        $this->assertSame('Hello', $cmd['datavalue']['value']['text']);
    }

    /**
     * @group unit
     */
    public function testParseValueV1_MonolingualTextWithDashes(): void
    {
        $cmd = [];
        $result = $this->qs->exposedParseValueV1('zh-hans:"你好"', $cmd);

        $this->assertTrue($result);
        $this->assertSame('monolingualtext', $cmd['datavalue']['type']);
        $this->assertSame('zh-hans', $cmd['datavalue']['value']['language']);
        $this->assertSame('你好', $cmd['datavalue']['value']['text']);
    }

    /**
     * @group unit
     */
    public function testParseValueV1_TimeValue(): void
    {
        $cmd = [];
        $result = $this->qs->exposedParseValueV1('+2023-01-15T00:00:00Z/11', $cmd);

        $this->assertTrue($result);
        $this->assertSame('time', $cmd['datavalue']['type']);
        $this->assertSame('+2023-01-15T00:00:00Z', $cmd['datavalue']['value']['time']);
        $this->assertSame(11, $cmd['datavalue']['value']['precision']);
        $this->assertSame(0, $cmd['datavalue']['value']['timezone']);
        $this->assertSame(0, $cmd['datavalue']['value']['before']);
        $this->assertSame(0, $cmd['datavalue']['value']['after']);
        // Gregorian calendar
        $this->assertSame('http://www.wikidata.org/entity/Q1985727', $cmd['datavalue']['value']['calendarmodel']);
    }

    /**
     * @group unit
     */
    public function testParseValueV1_TimeValueDefaultPrecision(): void
    {
        $cmd = [];
        $result = $this->qs->exposedParseValueV1('+2023-01-15T00:00:00Z', $cmd);

        $this->assertTrue($result);
        $this->assertSame('time', $cmd['datavalue']['type']);
        // Default precision should be 9 (year) when not specified
        $this->assertSame(9, $cmd['datavalue']['value']['precision']);
    }

    /**
     * @group unit
     */
    public function testParseValueV1_TimeValueJulianCalendar(): void
    {
        $cmd = [];
        $result = $this->qs->exposedParseValueV1('+1582-10-04T00:00:00Z/11/J', $cmd);

        $this->assertTrue($result);
        $this->assertSame('time', $cmd['datavalue']['type']);
        // Julian calendar
        $this->assertSame('http://www.wikidata.org/entity/Q1985786', $cmd['datavalue']['value']['calendarmodel']);
        $this->assertSame(11, $cmd['datavalue']['value']['precision']);
    }

    /**
     * @group unit
     */
    public function testParseValueV1_GPS(): void
    {
        $cmd = [];
        $result = $this->qs->exposedParseValueV1('@51.5074/0.1278', $cmd);

        $this->assertTrue($result);
        $this->assertSame('globecoordinate', $cmd['datavalue']['type']);
        $this->assertEquals(51.5074, $cmd['datavalue']['value']['latitude']);
        $this->assertEquals(0.1278, $cmd['datavalue']['value']['longitude']);
        $this->assertSame(0.000001, $cmd['datavalue']['value']['precision']);
        $this->assertSame('http://www.wikidata.org/entity/Q2', $cmd['datavalue']['value']['globe']);
    }

    /**
     * @group unit
     */
    public function testParseValueV1_GPSNegativeCoordinates(): void
    {
        $cmd = [];
        $result = $this->qs->exposedParseValueV1('@-33.8688/+151.2093', $cmd);

        $this->assertTrue($result);
        $this->assertSame('globecoordinate', $cmd['datavalue']['type']);
        $this->assertEquals(-33.8688, $cmd['datavalue']['value']['latitude']);
        $this->assertEquals(151.2093, $cmd['datavalue']['value']['longitude']);
    }

    /**
     * @group unit
     */
    public function testParseValueV1_GPSWithSpaces(): void
    {
        $cmd = [];
        $result = $this->qs->exposedParseValueV1('@ 51.5074 / 0.1278', $cmd);

        $this->assertTrue($result);
        $this->assertSame('globecoordinate', $cmd['datavalue']['type']);
    }

    /**
     * @group unit
     */
    public function testParseValueV1_QuantitySimple(): void
    {
        $cmd = [];
        $result = $this->qs->exposedParseValueV1('42', $cmd);

        $this->assertTrue($result);
        $this->assertSame('quantity', $cmd['datavalue']['type']);
        $this->assertSame('42', $cmd['datavalue']['value']['amount']);
        $this->assertSame('1', $cmd['datavalue']['value']['unit']);
    }

    /**
     * @group unit
     */
    public function testParseValueV1_QuantityWithUnit(): void
    {
        $cmd = [];
        $result = $this->qs->exposedParseValueV1('42U11573', $cmd);

        $this->assertTrue($result);
        $this->assertSame('quantity', $cmd['datavalue']['type']);
        $this->assertSame('42', $cmd['datavalue']['value']['amount']);
        $this->assertSame('http://www.wikidata.org/entity/Q11573', $cmd['datavalue']['value']['unit']);
    }

    /**
     * @group unit
     */
    public function testParseValueV1_QuantityDecimal(): void
    {
        $cmd = [];
        $result = $this->qs->exposedParseValueV1('+3.14', $cmd);

        $this->assertTrue($result);
        $this->assertSame('quantity', $cmd['datavalue']['type']);
        $this->assertSame('+3.14', $cmd['datavalue']['value']['amount']);
        $this->assertSame('1', $cmd['datavalue']['value']['unit']);
    }

    /**
     * @group unit
     */
    public function testParseValueV1_QuantityNegative(): void
    {
        $cmd = [];
        $result = $this->qs->exposedParseValueV1('-273', $cmd);

        $this->assertTrue($result);
        $this->assertSame('quantity', $cmd['datavalue']['type']);
        $this->assertSame('-273', $cmd['datavalue']['value']['amount']);
    }

    /**
     * @group unit
     */
    public function testParseValueV1_QuantityWithError(): void
    {
        $cmd = [];
        $result = $this->qs->exposedParseValueV1('42~2', $cmd);

        $this->assertTrue($result);
        $this->assertSame('quantity', $cmd['datavalue']['type']);
        $this->assertSame('42', $cmd['datavalue']['value']['amount']);
        $this->assertEquals(44, $cmd['datavalue']['value']['upperBound']);
        $this->assertEquals(40, $cmd['datavalue']['value']['lowerBound']);
        $this->assertSame('1', $cmd['datavalue']['value']['unit']);
    }

    /**
     * @group unit
     */
    public function testParseValueV1_QuantityWithErrorAndUnit(): void
    {
        $cmd = [];
        $result = $this->qs->exposedParseValueV1('100~5U11573', $cmd);

        $this->assertTrue($result);
        $this->assertSame('quantity', $cmd['datavalue']['type']);
        $this->assertSame('100', $cmd['datavalue']['value']['amount']);
        $this->assertEquals(105, $cmd['datavalue']['value']['upperBound']);
        $this->assertEquals(95, $cmd['datavalue']['value']['lowerBound']);
        $this->assertSame('http://www.wikidata.org/entity/Q11573', $cmd['datavalue']['value']['unit']);
    }

    /**
     * @group unit
     */
    public function testParseValueV1_Somevalue(): void
    {
        $cmd = [];
        $result = $this->qs->exposedParseValueV1('somevalue', $cmd);

        $this->assertTrue($result);
        $this->assertSame('somevalue', $cmd['datavalue']['type']);
        $this->assertSame('somevalue', $cmd['datavalue']['value']);
    }

    /**
     * @group unit
     */
    public function testParseValueV1_Novalue(): void
    {
        $cmd = [];
        $result = $this->qs->exposedParseValueV1('novalue', $cmd);

        $this->assertTrue($result);
        $this->assertSame('novalue', $cmd['datavalue']['type']);
        $this->assertSame('novalue', $cmd['datavalue']['value']);
    }

    /**
     * @group unit
     */
    public function testParseValueV1_LAST(): void
    {
        $cmd = [];
        $result = $this->qs->exposedParseValueV1('LAST', $cmd);

        $this->assertTrue($result);
        $this->assertSame('wikibase-entityid', $cmd['datavalue']['type']);
        $this->assertSame('item', $cmd['datavalue']['value']['entity-type']);
        $this->assertSame('LAST', $cmd['datavalue']['value']['id']);
    }

    /**
     * @group unit
     */
    public function testParseValueV1_UnknownValueFallback(): void
    {
        $cmd = [];
        $result = $this->qs->exposedParseValueV1('!!!garbage!!!', $cmd);

        // parseValueV1 does not return true for unknown values
        $this->assertNull($result);
        $this->assertSame('unknown', $cmd['datavalue']['type']);
        $this->assertSame('!!!garbage!!!', $cmd['datavalue']['text']);
        $this->assertArrayHasKey('error', $cmd);
        $this->assertSame('PARSE', $cmd['error'][0]);
    }

    /**
     * @group unit
     */
    public function testParseValueV1_WhitespaceTrimming(): void
    {
        $cmd = [];
        $result = $this->qs->exposedParseValueV1('  Q42  ', $cmd);

        $this->assertTrue($result);
        $this->assertSame('Q42', $cmd['datavalue']['value']['id']);
    }

    // =========================================================================
    //  2. isValidItemIdentifier()
    // =========================================================================

    /**
     * @group unit
     * @dataProvider validItemIdentifierProvider
     */
    public function testIsValidItemIdentifier_ValidCases(string $id): void
    {
        $this->assertTrue(
            $this->qs->exposedIsValidItemIdentifier($id),
            "Expected '{$id}' to be a valid item identifier"
        );
    }

    public static function validItemIdentifierProvider(): array
    {
        return [
            'item Q123'          => ['Q123'],
            'property P456'      => ['P456'],
            'lexeme L789'        => ['L789'],
            'lexeme form L123-F1'  => ['L123-F1'],
            'lexeme sense L123-S1' => ['L123-S1'],
            'large item Q99999999' => ['Q99999999'],
            'media M42'          => ['M42'],
        ];
    }

    /**
     * @group unit
     * @dataProvider invalidItemIdentifierProvider
     */
    public function testIsValidItemIdentifier_InvalidCases(string $id): void
    {
        $this->assertFalse(
            $this->qs->exposedIsValidItemIdentifier($id),
            "Expected '{$id}' to be an invalid item identifier"
        );
    }

    public static function invalidItemIdentifierProvider(): array
    {
        return [
            'empty string'            => [''],
            'plain number'            => ['123'],
            'lowercase q'             => ['q123'],
            'no digits'               => ['Q'],
            'text after digits'       => ['Q123abc'],
            'space in identifier'     => ['Q 123'],
            'LAST keyword'            => ['LAST'],
            'dash without subentity'  => ['L123-'],
            'invalid subentity type'  => ['L123-X1'],
            'Q with form suffix'      => ['Q123-F1'],
            'just a dash-F'           => ['-F1'],
        ];
    }

    // =========================================================================
    //  3. compareDatavalue()
    // =========================================================================

    /**
     * @group unit
     */
    public function testCompareDatavalue_DifferentTypesReturnFalse(): void
    {
        $d1 = (object) ['type' => 'string', 'value' => 'hello'];
        $d2 = (object) ['type' => 'quantity', 'value' => (object) ['amount' => 42]];

        $this->assertFalse($this->qs->exposedCompareDatavalue($d1, $d2));
    }

    /**
     * @group unit
     */
    public function testCompareDatavalue_IdenticalStrings(): void
    {
        $d1 = (object) ['type' => 'string', 'value' => 'hello'];
        $d2 = (object) ['type' => 'string', 'value' => 'hello'];

        $this->assertTrue($this->qs->exposedCompareDatavalue($d1, $d2));
    }

    /**
     * @group unit
     */
    public function testCompareDatavalue_DifferentStrings(): void
    {
        $d1 = (object) ['type' => 'string', 'value' => 'hello'];
        $d2 = (object) ['type' => 'string', 'value' => 'world'];

        $this->assertFalse($this->qs->exposedCompareDatavalue($d1, $d2));
    }

    /**
     * @group unit
     */
    public function testCompareDatavalue_EqualQuantities(): void
    {
        $d1 = (object) ['type' => 'quantity', 'value' => (object) ['amount' => '42']];
        $d2 = (object) ['type' => 'quantity', 'value' => (object) ['amount' => '42.0']];

        $this->assertTrue($this->qs->exposedCompareDatavalue($d1, $d2));
    }

    /**
     * @group unit
     */
    public function testCompareDatavalue_DifferentQuantities(): void
    {
        $d1 = (object) ['type' => 'quantity', 'value' => (object) ['amount' => '42']];
        $d2 = (object) ['type' => 'quantity', 'value' => (object) ['amount' => '43']];

        $this->assertFalse($this->qs->exposedCompareDatavalue($d1, $d2));
    }

    /**
     * @group unit
     */
    public function testCompareDatavalue_EqualTimes(): void
    {
        $d1 = (object) [
            'type' => 'time',
            'value' => (object) [
                'time' => '+2023-01-15T00:00:00Z',
                'calendarmodel' => 'http://www.wikidata.org/entity/Q1985727',
                'precision' => 11,
            ],
        ];
        $d2 = (object) [
            'type' => 'time',
            'value' => (object) [
                'time' => '+2023-01-15T00:00:00Z',
                'calendarmodel' => 'http://www.wikidata.org/entity/Q1985727',
                'precision' => 11,
            ],
        ];

        $this->assertTrue($this->qs->exposedCompareDatavalue($d1, $d2));
    }

    /**
     * @group unit
     */
    public function testCompareDatavalue_TimesLeadingZeroDance(): void
    {
        // The "Leading Zeroes Dance": +0002023-… should equal +2023-…
        $d1 = (object) [
            'type' => 'time',
            'value' => (object) [
                'time' => '+0002023-01-15T00:00:00Z',
                'calendarmodel' => 'http://www.wikidata.org/entity/Q1985727',
                'precision' => 11,
            ],
        ];
        $d2 = (object) [
            'type' => 'time',
            'value' => (object) [
                'time' => '+2023-01-15T00:00:00Z',
                'calendarmodel' => 'http://www.wikidata.org/entity/Q1985727',
                'precision' => 11,
            ],
        ];

        $this->assertTrue($this->qs->exposedCompareDatavalue($d1, $d2));
    }

    /**
     * @group unit
     */
    public function testCompareDatavalue_TimesDifferentCalendar(): void
    {
        $d1 = (object) [
            'type' => 'time',
            'value' => (object) [
                'time' => '+2023-01-15T00:00:00Z',
                'calendarmodel' => 'http://www.wikidata.org/entity/Q1985727', // Gregorian
                'precision' => 11,
            ],
        ];
        $d2 = (object) [
            'type' => 'time',
            'value' => (object) [
                'time' => '+2023-01-15T00:00:00Z',
                'calendarmodel' => 'http://www.wikidata.org/entity/Q1985786', // Julian
                'precision' => 11,
            ],
        ];

        $this->assertFalse($this->qs->exposedCompareDatavalue($d1, $d2));
    }

    /**
     * @group unit
     */
    public function testCompareDatavalue_TimesDifferentPrecision(): void
    {
        $d1 = (object) [
            'type' => 'time',
            'value' => (object) [
                'time' => '+2023-01-15T00:00:00Z',
                'calendarmodel' => 'http://www.wikidata.org/entity/Q1985727',
                'precision' => 11,
            ],
        ];
        $d2 = (object) [
            'type' => 'time',
            'value' => (object) [
                'time' => '+2023-01-15T00:00:00Z',
                'calendarmodel' => 'http://www.wikidata.org/entity/Q1985727',
                'precision' => 9,
            ],
        ];

        $this->assertFalse($this->qs->exposedCompareDatavalue($d1, $d2));
    }

    /**
     * @group unit
     */
    public function testCompareDatavalue_EqualGlobeCoordinates(): void
    {
        $d1 = (object) [
            'type' => 'globecoordinate',
            'value' => (object) [
                'latitude' => 51.5074,
                'longitude' => -0.1278,
                'globe' => 'http://www.wikidata.org/entity/Q2',
            ],
        ];
        $d2 = (object) [
            'type' => 'globecoordinate',
            'value' => (object) [
                'latitude' => 51.5074,
                'longitude' => -0.1278,
                'globe' => 'http://www.wikidata.org/entity/Q2',
            ],
        ];

        $this->assertTrue($this->qs->exposedCompareDatavalue($d1, $d2));
    }

    /**
     * @group unit
     */
    public function testCompareDatavalue_DifferentGlobeCoordinates(): void
    {
        $d1 = (object) [
            'type' => 'globecoordinate',
            'value' => (object) [
                'latitude' => 51.5074,
                'longitude' => -0.1278,
                'globe' => 'http://www.wikidata.org/entity/Q2',
            ],
        ];
        $d2 = (object) [
            'type' => 'globecoordinate',
            'value' => (object) [
                'latitude' => 40.7128,
                'longitude' => -74.0060,
                'globe' => 'http://www.wikidata.org/entity/Q2',
            ],
        ];

        $this->assertFalse($this->qs->exposedCompareDatavalue($d1, $d2));
    }

    /**
     * @group unit
     */
    public function testCompareDatavalue_EqualMonolingualText(): void
    {
        $d1 = (object) [
            'type' => 'monolingualtext',
            'value' => (object) ['text' => 'Hello', 'language' => 'en'],
        ];
        $d2 = (object) [
            'type' => 'monolingualtext',
            'value' => (object) ['text' => 'Hello', 'language' => 'en'],
        ];

        $this->assertTrue($this->qs->exposedCompareDatavalue($d1, $d2));
    }

    /**
     * @group unit
     */
    public function testCompareDatavalue_DifferentMonolingualTextLanguage(): void
    {
        $d1 = (object) [
            'type' => 'monolingualtext',
            'value' => (object) ['text' => 'Hello', 'language' => 'en'],
        ];
        $d2 = (object) [
            'type' => 'monolingualtext',
            'value' => (object) ['text' => 'Hello', 'language' => 'de'],
        ];

        $this->assertFalse($this->qs->exposedCompareDatavalue($d1, $d2));
    }

    /**
     * @group unit
     */
    public function testCompareDatavalue_EntityIdByNumericId(): void
    {
        $d1 = (object) [
            'type' => 'wikibase-entityid',
            'value' => (object) ['entity-type' => 'item', 'numeric-id' => 42],
        ];
        $d2 = (object) [
            'type' => 'wikibase-entityid',
            'value' => (object) ['entity-type' => 'item', 'numeric-id' => 42],
        ];

        $this->assertTrue($this->qs->exposedCompareDatavalue($d1, $d2));
    }

    /**
     * @group unit
     */
    public function testCompareDatavalue_EntityIdById(): void
    {
        $d1 = (object) [
            'type' => 'wikibase-entityid',
            'value' => (object) ['entity-type' => 'item', 'id' => 'Q42'],
        ];
        $d2 = (object) [
            'type' => 'wikibase-entityid',
            'value' => (object) ['entity-type' => 'item', 'id' => 'Q42'],
        ];

        $this->assertTrue($this->qs->exposedCompareDatavalue($d1, $d2));
    }

    /**
     * @group unit
     */
    public function testCompareDatavalue_EntityIdDifferentEntityType(): void
    {
        $d1 = (object) [
            'type' => 'wikibase-entityid',
            'value' => (object) ['entity-type' => 'item', 'id' => 'Q42'],
        ];
        $d2 = (object) [
            'type' => 'wikibase-entityid',
            'value' => (object) ['entity-type' => 'property', 'id' => 'P42'],
        ];

        $this->assertFalse($this->qs->exposedCompareDatavalue($d1, $d2));
    }

    /**
     * @group unit
     */
    public function testCompareDatavalue_UnknownTypeReturnsFalse(): void
    {
        $d1 = (object) ['type' => 'imaginary', 'value' => 'foo'];
        $d2 = (object) ['type' => 'imaginary', 'value' => 'foo'];

        $this->assertFalse($this->qs->exposedCompareDatavalue($d1, $d2));
    }

    // =========================================================================
    //  4. getEntityType()
    // =========================================================================

    /**
     * @group unit
     */
    public function testGetEntityType_Item(): void
    {
        $this->assertSame('item', $this->qs->exposedGetEntityType('Q42'));
    }

    /**
     * @group unit
     */
    public function testGetEntityType_Property(): void
    {
        $this->assertSame('property', $this->qs->exposedGetEntityType('P31'));
    }

    /**
     * @group unit
     */
    public function testGetEntityType_Lexeme(): void
    {
        $this->assertSame('lexeme', $this->qs->exposedGetEntityType('L123'));
    }

    /**
     * @group unit
     */
    public function testGetEntityType_Form(): void
    {
        $this->assertSame('form', $this->qs->exposedGetEntityType('L123-F1'));
    }

    /**
     * @group unit
     */
    public function testGetEntityType_Sense(): void
    {
        $this->assertSame('sense', $this->qs->exposedGetEntityType('L123-S1'));
    }

    /**
     * @group unit
     */
    public function testGetEntityType_CaseInsensitive(): void
    {
        // getEntityType uppercases the input
        $this->assertSame('item', $this->qs->exposedGetEntityType('q42'));
    }

    /**
     * @group unit
     */
    public function testGetEntityType_UnknownReturnsUnknown(): void
    {
        $this->assertSame('unknown', $this->qs->exposedGetEntityType('X999'));
    }

    // =========================================================================
    //  5. commandSetLabel() – single-quote string interpolation bug
    // =========================================================================

    /**
     * Documents the single-quote string interpolation bug in commandSetLabel().
     *
     * Previously the code used single-quoted strings which prevented
     * variable interpolation. The fix changed them to double quotes.
     *
     * @group unit
     */
    public function testCommandSetLabelUsesDoubleQuotesForInterpolation(): void
    {
        $source = file_get_contents(
            dirname(__DIR__) . '/public_html/quickstatements.php'
        );

        // Verify the fix: double-quoted string is now used so the variable is interpolated
        $this->assertMatchesRegularExpression(
            '/commandSetLabel.*?"Already has that label for \{\$command->language\}"/s',
            $source,
            'commandSetLabel should use double-quoted string so {$command->language} is interpolated'
        );

        // Verify double quotes actually interpolate correctly
        $command = (object) ['language' => 'en'];
        $fixed = "Already has that label for {$command->language}";
        $this->assertSame(
            'Already has that label for en',
            $fixed,
            'Double-quoted strings correctly interpolate the language variable'
        );
    }

    // =========================================================================
    //  6. commandSetDescription() – single-quote string interpolation bug
    // =========================================================================

    /**
     * Verifies that commandSetDescription uses double-quoted strings
     * so that {$command->language} is properly interpolated.
     *
     * @group unit
     */
    public function testCommandSetDescriptionUsesDoubleQuotesForInterpolation(): void
    {
        $source = file_get_contents(
            dirname(__DIR__) . '/public_html/quickstatements.php'
        );

        $this->assertMatchesRegularExpression(
            '/commandSetDescription.*?"Already has that description for \{\$command->language\}"/s',
            $source,
            'commandSetDescription should use double-quoted string so {$command->language} is interpolated'
        );

        $command = (object) ['language' => 'de'];
        $fixed = "Already has that description for {$command->language}";
        $this->assertSame(
            'Already has that description for de',
            $fixed,
            'Double-quoted strings correctly interpolate the language variable'
        );
    }

    // =========================================================================
    //  7. commandSetSitelink() – single-quote string interpolation bug
    // =========================================================================

    /**
     * Verifies that commandSetSitelink uses double-quoted strings
     * so that {$command->site} is properly interpolated.
     *
     * @group unit
     */
    public function testCommandSetSitelinkUsesDoubleQuotesForInterpolation(): void
    {
        $source = file_get_contents(
            dirname(__DIR__) . '/public_html/quickstatements.php'
        );

        $this->assertMatchesRegularExpression(
            '/commandSetSitelink.*?"Already has that sitelink for \{\$command->site\}"/s',
            $source,
            'commandSetSitelink should use double-quoted string so {$command->site} is interpolated'
        );

        $command = (object) ['site' => 'enwiki'];
        $fixed = "Already has that sitelink for {$command->site}";
        $this->assertSame(
            'Already has that sitelink for enwiki',
            $fixed,
            'Double-quoted strings correctly interpolate the site variable'
        );
    }

    // =========================================================================
    //  8. generateTemporaryBatchID()
    // =========================================================================

    /**
     * @group unit
     */
    public function testGenerateTemporaryBatchID_ReturnsHexString(): void
    {
        $id = $this->qs->generateTemporaryBatchID();

        $this->assertNotEmpty($id, 'Batch ID should not be empty');
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]+$/',
            $id,
            'Batch ID should consist only of lowercase hex characters'
        );
    }

    /**
     * @group unit
     */
    public function testGenerateTemporaryBatchID_IsUnique(): void
    {
        $id1 = $this->qs->generateTemporaryBatchID();
        // Tiny pause to ensure uniqid() produces a different value
        usleep(1);
        $id2 = $this->qs->generateTemporaryBatchID();

        $this->assertNotSame($id1, $id2, 'Two generated batch IDs should be different');
    }

    /**
     * @group unit
     */
    public function testGenerateAndUseTemporaryBatchID_SetsProperty(): void
    {
        $id = $this->qs->generateAndUseTemporaryBatchID();

        $this->assertNotEmpty($id);
        $this->assertSame($id, $this->qs->temporary_batch_id);
    }

    // =========================================================================
    //  9. getSnakType()
    // =========================================================================

    /**
     * @group unit
     */
    public function testGetSnakType_Somevalue(): void
    {
        $dv = (object) ['value' => 'somevalue', 'type' => 'somevalue'];
        $this->assertSame('somevalue', $this->qs->exposedGetSnakType($dv));
    }

    /**
     * @group unit
     */
    public function testGetSnakType_Novalue(): void
    {
        $dv = (object) ['value' => 'novalue', 'type' => 'novalue'];
        $this->assertSame('novalue', $this->qs->exposedGetSnakType($dv));
    }

    /**
     * @group unit
     */
    public function testGetSnakType_RegularValueReturnsValue(): void
    {
        $dv = (object) [
            'value' => (object) ['entity-type' => 'item', 'id' => 'Q42'],
            'type'  => 'wikibase-entityid',
        ];
        $this->assertSame('value', $this->qs->exposedGetSnakType($dv));
    }

    /**
     * @group unit
     */
    public function testGetSnakType_StringValueReturnsValue(): void
    {
        $dv = (object) ['value' => 'some arbitrary string', 'type' => 'string'];
        $this->assertSame('value', $this->qs->exposedGetSnakType($dv));
    }

    // =========================================================================
    //  11. usleep calculation bug documentation
    // =========================================================================

    /**
     * Documents the sleep/usleep bug.
     *
     * The default sleep property is 0.1 (intended as 0.1 seconds = 100 ms).
     * The code does: usleep($this->sleep * 1000)
     * This gives: usleep(0.1 * 1000) = usleep(100) = 100 MICRO-seconds
     *
     * To sleep 100 milliseconds, it should be: usleep(0.1 * 1_000_000) = usleep(100000)
     * Or equivalently: usleep($this->sleep * 1000000)
     *
     * The multiplier must be 1_000_000 (microseconds per second).
     * Previously it was 1_000, making the sleep 1000x shorter than intended.
     *
     * @group unit
     */
    public function testSleepCalculationFix(): void
    {
        // Verify the default sleep value
        $this->assertSame(0.1, $this->qs->sleep, 'Default sleep should be 0.1 seconds');

        // With the fix, 0.1 seconds * 1_000_000 = 100_000 microseconds = 100 ms
        $sleepValue = $this->qs->sleep;
        $expectedMicroseconds = (int)($sleepValue * 1000000);

        $this->assertEquals(
            100000,
            $expectedMicroseconds,
            'usleep((int)($this->sleep * 1000000)) = usleep(100000) = 100 milliseconds'
        );

        // Verify the fixed source code uses * 1000000
        $source = file_get_contents(
            dirname(__DIR__) . '/public_html/quickstatements.php'
        );
        $this->assertMatchesRegularExpression(
            '/usleep\s*\(\s*\(int\)\s*\(\s*\$this->sleep\s*\*\s*1000000\s*\)\s*\)/',
            $source,
            'Source code should contain usleep((int)($this->sleep * 1000000)) – the correct multiplier'
        );
    }

    // =========================================================================
    //  Additional edge-case tests
    // =========================================================================

    /**
     * @group unit
     */
    public function testParseValueV1_EmptyStringBecomesUnknown(): void
    {
        $cmd = [];
        $result = $this->qs->exposedParseValueV1('', $cmd);

        // An empty string doesn't match any pattern, so falls through to unknown
        $this->assertNull($result);
        $this->assertSame('unknown', $cmd['datavalue']['type']);
    }

    /**
     * @group unit
     */
    public function testConfigSiteSetup(): void
    {
        $site = $this->qs->exposedGetSite();

        $this->assertSame('http://www.wikidata.org/entity/', $site->entityBase);
        $this->assertSame('item', $site->types->Q->type);
        $this->assertSame('property', $site->types->P->type);
        $this->assertSame('lexeme', $site->types->L->type);
    }

    /**
     * Verify that the sleep property is publicly accessible and has the expected default.
     *
     * @group unit
     */
    public function testDefaultSleepProperty(): void
    {
        $qs = new TestableQuickStatements();
        $this->assertSame(0.1, $qs->sleep);
    }

    /**
     * @group unit
     */
    public function testCompareDatavalue_EntityIdMismatchedIds(): void
    {
        $d1 = (object) [
            'type' => 'wikibase-entityid',
            'value' => (object) ['entity-type' => 'item', 'id' => 'Q1'],
        ];
        $d2 = (object) [
            'type' => 'wikibase-entityid',
            'value' => (object) ['entity-type' => 'item', 'id' => 'Q2'],
        ];

        $this->assertFalse($this->qs->exposedCompareDatavalue($d1, $d2));
    }

    /**
     * @group unit
     */
    public function testCompareDatavalue_EntityIdWithNoMatchingIdFields(): void
    {
        // Neither numeric-id nor id fields match
        $d1 = (object) [
            'type' => 'wikibase-entityid',
            'value' => (object) ['entity-type' => 'item'],
        ];
        $d2 = (object) [
            'type' => 'wikibase-entityid',
            'value' => (object) ['entity-type' => 'item'],
        ];

        // Both have same entity-type but no id or numeric-id, so returns false
        $this->assertFalse($this->qs->exposedCompareDatavalue($d1, $d2));
    }

    // =========================================================================
    //  Lexeme support — V1 import parsing
    // =========================================================================

    /**
     * @group unit
     */
    public function testImportV1_CreateLexeme(): void
    {
        $data = "CREATE_LEXEME\tQ7725\tQ1084\ten:\"water\"";
        $result = $this->qs->exposedImportDataFromV1($data);

        $this->assertSame('OK', $result['status']);
        $this->assertCount(1, $result['data']['commands']);
        $cmd = $result['data']['commands'][0];
        $this->assertSame('create', $cmd['action']);
        $this->assertSame('lexeme', $cmd['type']);
        $this->assertSame('Q7725', $cmd['data']['language']);
        $this->assertSame('Q1084', $cmd['data']['lexicalCategory']);
        $this->assertSame('water', $cmd['data']['lemmas']['en']['value']);
        $this->assertSame('en', $cmd['data']['lemmas']['en']['language']);
    }

    /**
     * @group unit
     */
    public function testImportV1_CreateLexemeMultipleLemmas(): void
    {
        $data = "CREATE_LEXEME\tQ7725\tQ1084\ten:\"water\"\tfr:\"eau\"";
        $result = $this->qs->exposedImportDataFromV1($data);

        $this->assertSame('OK', $result['status']);
        $this->assertCount(1, $result['data']['commands']);
        $cmd = $result['data']['commands'][0];
        $this->assertSame('create', $cmd['action']);
        $this->assertSame('lexeme', $cmd['type']);
        $this->assertSame('water', $cmd['data']['lemmas']['en']['value']);
        $this->assertSame('eau', $cmd['data']['lemmas']['fr']['value']);
    }

    /**
     * @group unit
     */
    public function testImportV1_CreateLexemeTooFewColumns(): void
    {
        $data = "CREATE_LEXEME\tQ7725";
        $result = $this->qs->exposedImportDataFromV1($data);

        $this->assertSame('OK', $result['status']);
        $this->assertCount(1, $result['data']['commands']);
        $cmd = $result['data']['commands'][0];
        $this->assertArrayHasKey('error', $cmd);
    }

    /**
     * @group unit
     */
    public function testImportV1_SetLemma(): void
    {
        $data = "L123\tLemma_en\t\"water\"";
        $result = $this->qs->exposedImportDataFromV1($data);

        $this->assertSame('OK', $result['status']);
        $this->assertCount(1, $result['data']['commands']);
        $cmd = $result['data']['commands'][0];
        $this->assertSame('add', $cmd['action']);
        $this->assertSame('lemma', $cmd['what']);
        $this->assertSame('L123', $cmd['item']);
        $this->assertSame('en', $cmd['language']);
        $this->assertSame('water', $cmd['value']);
    }

    /**
     * @group unit
     */
    public function testImportV1_SetLemmaWithLAST(): void
    {
        $data = "CREATE_LEXEME\tQ7725\tQ1084\ten:\"water\"\nLAST\tLemma_fr\t\"eau\"";
        $result = $this->qs->exposedImportDataFromV1($data);

        $this->assertSame('OK', $result['status']);
        $this->assertCount(2, $result['data']['commands']);
        $cmd = $result['data']['commands'][1];
        $this->assertSame('add', $cmd['action']);
        $this->assertSame('lemma', $cmd['what']);
        $this->assertSame('LAST', $cmd['item']);
        $this->assertSame('fr', $cmd['language']);
        $this->assertSame('eau', $cmd['value']);
    }

    /**
     * @group unit
     */
    public function testImportV1_SetLexicalCategory(): void
    {
        $data = "L123\tLEXICAL_CATEGORY\tQ1084";
        $result = $this->qs->exposedImportDataFromV1($data);

        $this->assertSame('OK', $result['status']);
        $this->assertCount(1, $result['data']['commands']);
        $cmd = $result['data']['commands'][0];
        $this->assertSame('add', $cmd['action']);
        $this->assertSame('lexical_category', $cmd['what']);
        $this->assertSame('L123', $cmd['item']);
        $this->assertSame('Q1084', $cmd['value']);
    }

    /**
     * @group unit
     */
    public function testImportV1_SetLanguage(): void
    {
        $data = "L123\tLANGUAGE\tQ7725";
        $result = $this->qs->exposedImportDataFromV1($data);

        $this->assertSame('OK', $result['status']);
        $this->assertCount(1, $result['data']['commands']);
        $cmd = $result['data']['commands'][0];
        $this->assertSame('add', $cmd['action']);
        $this->assertSame('language', $cmd['what']);
        $this->assertSame('L123', $cmd['item']);
        $this->assertSame('Q7725', $cmd['value']);
    }

    /**
     * @group unit
     */
    public function testImportV1_AddForm(): void
    {
        $data = "L123\tADD_FORM\ten:\"running\"\tQ1,Q2";
        $result = $this->qs->exposedImportDataFromV1($data);

        $this->assertSame('OK', $result['status']);
        $this->assertCount(1, $result['data']['commands']);
        $cmd = $result['data']['commands'][0];
        $this->assertSame('create', $cmd['action']);
        $this->assertSame('form', $cmd['type']);
        $this->assertSame('L123', $cmd['item']);
        $this->assertSame('running', $cmd['data']['representations']['en']['value']);
        $this->assertSame('en', $cmd['data']['representations']['en']['language']);
        $this->assertSame(['Q1', 'Q2'], $cmd['data']['grammaticalFeatures']);
    }

    /**
     * @group unit
     */
    public function testImportV1_AddFormNoFeatures(): void
    {
        $data = "L123\tADD_FORM\ten:\"running\"";
        $result = $this->qs->exposedImportDataFromV1($data);

        $this->assertSame('OK', $result['status']);
        $this->assertCount(1, $result['data']['commands']);
        $cmd = $result['data']['commands'][0];
        $this->assertSame('create', $cmd['action']);
        $this->assertSame('form', $cmd['type']);
        $this->assertSame('L123', $cmd['item']);
        $this->assertSame('running', $cmd['data']['representations']['en']['value']);
        $this->assertSame([], $cmd['data']['grammaticalFeatures']);
    }

    /**
     * @group unit
     */
    public function testImportV1_AddFormMultipleRepresentations(): void
    {
        $data = "L123\tADD_FORM\ten:\"color\"\ten-gb:\"colour\"\tQ1";
        $result = $this->qs->exposedImportDataFromV1($data);

        $this->assertSame('OK', $result['status']);
        $this->assertCount(1, $result['data']['commands']);
        $cmd = $result['data']['commands'][0];
        $this->assertSame('create', $cmd['action']);
        $this->assertSame('form', $cmd['type']);
        $this->assertSame('color', $cmd['data']['representations']['en']['value']);
        $this->assertSame('colour', $cmd['data']['representations']['en-gb']['value']);
        $this->assertSame(['Q1'], $cmd['data']['grammaticalFeatures']);
    }

    /**
     * @group unit
     */
    public function testImportV1_AddSense(): void
    {
        $data = "L123\tADD_SENSE\ten:\"act of running\"";
        $result = $this->qs->exposedImportDataFromV1($data);

        $this->assertSame('OK', $result['status']);
        $this->assertCount(1, $result['data']['commands']);
        $cmd = $result['data']['commands'][0];
        $this->assertSame('create', $cmd['action']);
        $this->assertSame('sense', $cmd['type']);
        $this->assertSame('L123', $cmd['item']);
        $this->assertSame('act of running', $cmd['data']['glosses']['en']['value']);
        $this->assertSame('en', $cmd['data']['glosses']['en']['language']);
    }

    /**
     * @group unit
     */
    public function testImportV1_AddSenseMultipleGlosses(): void
    {
        $data = "L123\tADD_SENSE\ten:\"water\"\tfr:\"eau\"";
        $result = $this->qs->exposedImportDataFromV1($data);

        $this->assertSame('OK', $result['status']);
        $this->assertCount(1, $result['data']['commands']);
        $cmd = $result['data']['commands'][0];
        $this->assertSame('create', $cmd['action']);
        $this->assertSame('sense', $cmd['type']);
        $this->assertSame('water', $cmd['data']['glosses']['en']['value']);
        $this->assertSame('eau', $cmd['data']['glosses']['fr']['value']);
    }

    /**
     * @group unit
     */
    public function testImportV1_SetFormRepresentation(): void
    {
        $data = "L123-F1\tRep_en\t\"running\"";
        $result = $this->qs->exposedImportDataFromV1($data);

        $this->assertSame('OK', $result['status']);
        $this->assertCount(1, $result['data']['commands']);
        $cmd = $result['data']['commands'][0];
        $this->assertSame('add', $cmd['action']);
        $this->assertSame('representation', $cmd['what']);
        $this->assertSame('L123-F1', $cmd['item']);
        $this->assertSame('en', $cmd['language']);
        $this->assertSame('running', $cmd['value']);
    }

    /**
     * @group unit
     */
    public function testImportV1_SetGrammaticalFeature(): void
    {
        $data = "L123-F1\tGRAMMATICAL_FEATURE\tQ1,Q2,Q3";
        $result = $this->qs->exposedImportDataFromV1($data);

        $this->assertSame('OK', $result['status']);
        $this->assertCount(1, $result['data']['commands']);
        $cmd = $result['data']['commands'][0];
        $this->assertSame('add', $cmd['action']);
        $this->assertSame('grammatical_feature', $cmd['what']);
        $this->assertSame('L123-F1', $cmd['item']);
        $this->assertSame(['Q1', 'Q2', 'Q3'], $cmd['value']);
    }

    /**
     * @group unit
     */
    public function testImportV1_SetSenseGloss(): void
    {
        $data = "L123-S1\tGloss_en\t\"act of running\"";
        $result = $this->qs->exposedImportDataFromV1($data);

        $this->assertSame('OK', $result['status']);
        $this->assertCount(1, $result['data']['commands']);
        $cmd = $result['data']['commands'][0];
        $this->assertSame('add', $cmd['action']);
        $this->assertSame('gloss', $cmd['what']);
        $this->assertSame('L123-S1', $cmd['item']);
        $this->assertSame('en', $cmd['language']);
        $this->assertSame('act of running', $cmd['value']);
    }

    /**
     * @group unit
     */
    public function testImportV1_AddFormWithLAST(): void
    {
        $data = "CREATE_LEXEME\tQ7725\tQ1084\ten:\"water\"\nLAST\tADD_FORM\ten:\"waters\"\tQ146786";
        $result = $this->qs->exposedImportDataFromV1($data);

        $this->assertSame('OK', $result['status']);
        $this->assertCount(2, $result['data']['commands']);

        $cmd0 = $result['data']['commands'][0];
        $this->assertSame('create', $cmd0['action']);
        $this->assertSame('lexeme', $cmd0['type']);

        $cmd1 = $result['data']['commands'][1];
        $this->assertSame('create', $cmd1['action']);
        $this->assertSame('form', $cmd1['type']);
        $this->assertSame('LAST', $cmd1['item']);
        $this->assertSame(['Q146786'], $cmd1['data']['grammaticalFeatures']);
    }

    /**
     * @group unit
     */
    public function testImportV1_AddSenseWithLAST(): void
    {
        $data = "CREATE_LEXEME\tQ7725\tQ1084\ten:\"water\"\nLAST\tADD_SENSE\ten:\"transparent liquid\"";
        $result = $this->qs->exposedImportDataFromV1($data);

        $this->assertSame('OK', $result['status']);
        $this->assertCount(2, $result['data']['commands']);

        $cmd1 = $result['data']['commands'][1];
        $this->assertSame('create', $cmd1['action']);
        $this->assertSame('sense', $cmd1['type']);
        $this->assertSame('LAST', $cmd1['item']);
    }

    /**
     * @group unit
     */
    public function testImportV1_LexemeStatementsStillWork(): void
    {
        $data = "L123\tP31\tQ5";
        $result = $this->qs->exposedImportDataFromV1($data);

        $this->assertSame('OK', $result['status']);
        $this->assertCount(1, $result['data']['commands']);
        $cmd = $result['data']['commands'][0];
        $this->assertSame('add', $cmd['action']);
        $this->assertSame('statement', $cmd['what']);
        $this->assertSame('L123', $cmd['item']);
        $this->assertSame('P31', $cmd['property']);
    }

    /**
     * @group unit
     */
    public function testImportV1_FormStatementsWork(): void
    {
        $data = "L123-F1\tP31\tQ5";
        $result = $this->qs->exposedImportDataFromV1($data);

        $this->assertSame('OK', $result['status']);
        $this->assertCount(1, $result['data']['commands']);
        $cmd = $result['data']['commands'][0];
        $this->assertSame('add', $cmd['action']);
        $this->assertSame('statement', $cmd['what']);
        $this->assertSame('L123-F1', $cmd['item']);
        $this->assertSame('P31', $cmd['property']);
    }

    /**
     * @group unit
     */
    public function testImportV1_SenseStatementsWork(): void
    {
        $data = "L123-S1\tP31\tQ5";
        $result = $this->qs->exposedImportDataFromV1($data);

        $this->assertSame('OK', $result['status']);
        $this->assertCount(1, $result['data']['commands']);
        $cmd = $result['data']['commands'][0];
        $this->assertSame('add', $cmd['action']);
        $this->assertSame('statement', $cmd['what']);
        $this->assertSame('L123-S1', $cmd['item']);
        $this->assertSame('P31', $cmd['property']);
    }

    /**
     * @group unit
     */
    public function testImportV1_CreateLexemeWithComment(): void
    {
        $data = "CREATE_LEXEME\tQ7725\tQ1084\ten:\"water\" /* adding water lexeme */";
        $result = $this->qs->exposedImportDataFromV1($data);

        $this->assertSame('OK', $result['status']);
        $this->assertCount(1, $result['data']['commands']);
        $cmd = $result['data']['commands'][0];
        $this->assertSame('create', $cmd['action']);
        $this->assertSame('lexeme', $cmd['type']);
        $this->assertSame('adding water lexeme', $cmd['summary']);
    }

    // =========================================================================
    //  Lexeme — LAST propagation during execution (MockableQuickStatements)
    // =========================================================================

    /**
     * Reproduces the exact scenario from T220985#11744261:
     *
     *   CREATE_LEXEME  Q12107  Q147276  br:"Montroulez"
     *   LAST  P12846  "m/montroulez/"
     *   LAST  ADD_FORM  br:"Montroulez"  Q110786
     *   LAST  ADD_SENSE  fr:"commune française"
     *   LAST  P5137  Q202368
     *
     * After ADD_FORM, LAST must still be the lexeme so that ADD_SENSE and
     * the final statement target the lexeme, not the form.
     *
     * @group unit
     */
    public function testLastPropagation_Phab_T220985(): void
    {
        $mqs = new MockableQuickStatements();
        $cmds = $mqs->importAndRun(implode("\n", [
            "CREATE_LEXEME\tQ12107\tQ147276\tbr:\"Montroulez\"",
            "LAST\tP12846\t\"m/montroulez/\"",
            "LAST\tADD_FORM\tbr:\"Montroulez\"\tQ110786",
            "LAST\tADD_SENSE\tfr:\"commune française\"",
            "LAST\tP5137\tQ202368",
        ]));

        // All five must succeed
        foreach ($cmds as $i => $c) {
            $this->assertSame('done', $c->status, "Command $i failed: " . ($c->message ?? ''));
        }

        // 0: CREATE_LEXEME → item = L9001
        $lexemeId = $cmds[0]->item;
        $this->assertMatchesRegularExpression('/^L\d+$/', $lexemeId);

        // 1: statement on the lexeme
        $this->assertSame($lexemeId, $cmds[1]->item);

        // 2: ADD_FORM → command's own item is the form ID, but LAST stays on lexeme
        $this->assertMatchesRegularExpression('/^L\d+-F\d+$/', $cmds[2]->item);

        // 3: ADD_SENSE must target the LEXEME, not the form
        $senseItem = $cmds[3]->item;
        $this->assertMatchesRegularExpression('/^L\d+-S\d+$/', $senseItem);
        // The lexemeId passed to wbladdsense must be the lexeme, not the form
        $senseAction = $mqs->actionLog[3];
        $this->assertSame('wbladdsense', $senseAction->action);
        $this->assertSame($lexemeId, $senseAction->lexemeId);

        // 4: final statement must target the lexeme
        $this->assertSame($lexemeId, $cmds[4]->item);
    }

    /**
     * After CREATE_LEXEME + multiple ADD_FORMs, LAST stays on the lexeme.
     *
     * @group unit
     */
    public function testLastPropagation_MultipleForms(): void
    {
        $mqs = new MockableQuickStatements();
        $cmds = $mqs->importAndRun(implode("\n", [
            "CREATE_LEXEME\tQ7725\tQ1084\ten:\"water\"",
            "LAST\tADD_FORM\ten:\"water\"\tQ110786",
            "LAST\tADD_FORM\ten:\"waters\"\tQ146786",
            "LAST\tADD_SENSE\ten:\"transparent liquid\"",
        ]));

        $lexemeId = $cmds[0]->item;

        // Both forms must have been added to the lexeme
        $this->assertSame('wbladdform', $mqs->actionLog[1]->action);
        $this->assertSame($lexemeId, $mqs->actionLog[1]->lexemeId);
        $this->assertSame('wbladdform', $mqs->actionLog[2]->action);
        $this->assertSame($lexemeId, $mqs->actionLog[2]->lexemeId);

        // Sense also targets the lexeme
        $this->assertSame('wbladdsense', $mqs->actionLog[3]->action);
        $this->assertSame($lexemeId, $mqs->actionLog[3]->lexemeId);

        // LAST is still the lexeme
        $this->assertSame($lexemeId, $mqs->getLastItem());
    }

    /**
     * After CREATE_LEXEME + ADD_SENSE, LAST stays on the lexeme so a
     * following statement targets the lexeme.
     *
     * @group unit
     */
    public function testLastPropagation_SenseThenStatement(): void
    {
        $mqs = new MockableQuickStatements();
        $cmds = $mqs->importAndRun(implode("\n", [
            "CREATE_LEXEME\tQ7725\tQ1084\ten:\"water\"",
            "LAST\tADD_SENSE\ten:\"transparent liquid\"",
            "LAST\tP31\tQ5",
        ]));

        $lexemeId = $cmds[0]->item;

        // The statement must target the lexeme, not the sense
        $this->assertSame($lexemeId, $cmds[2]->item);
    }

    /**
     * A plain CREATE (item) followed by LAST still works: LAST is the
     * new Q-ID.  Regression guard.
     *
     * @group unit
     */
    public function testLastPropagation_CreateItemStillWorks(): void
    {
        $mqs = new MockableQuickStatements();
        $cmds = $mqs->importAndRun(implode("\n", [
            "CREATE",
            "LAST\tLen\t\"Test item\"",
        ]));

        $this->assertSame('done', $cmds[0]->status);
        $this->assertSame('done', $cmds[1]->status);
        $itemId = $cmds[0]->item;
        $this->assertMatchesRegularExpression('/^Q\d+$/', $itemId);
        $this->assertSame($itemId, $cmds[1]->item);
    }

    /**
     * Explicit lexeme ID (not LAST) for ADD_FORM still works.
     *
     * @group unit
     */
    public function testLastPropagation_ExplicitLexemeIdForForm(): void
    {
        $mqs = new MockableQuickStatements();
        $cmds = $mqs->importAndRun("L999\tADD_FORM\ten:\"running\"\tQ1");

        $this->assertSame('done', $cmds[0]->status);
        $this->assertSame('wbladdform', $mqs->actionLog[0]->action);
        $this->assertSame('L999', $mqs->actionLog[0]->lexemeId);
        // LAST should be L999, not the form ID
        $this->assertSame('L999', $mqs->getLastItem());
    }

    /**
     * Explicit lexeme ID for ADD_SENSE still works.
     *
     * @group unit
     */
    public function testLastPropagation_ExplicitLexemeIdForSense(): void
    {
        $mqs = new MockableQuickStatements();
        $cmds = $mqs->importAndRun("L999\tADD_SENSE\ten:\"some gloss\"");

        $this->assertSame('done', $cmds[0]->status);
        $this->assertSame('wbladdsense', $mqs->actionLog[0]->action);
        $this->assertSame('L999', $mqs->actionLog[0]->lexemeId);
        $this->assertSame('L999', $mqs->getLastItem());
    }

    /**
     * Lemma, lexical_category, language, representation, gloss, and
     * grammatical_feature commands all call the correct API actions.
     *
     * @group unit
     */
    public function testExecution_LexemeEditCommands(): void
    {
        $mqs = new MockableQuickStatements();
        $cmds = $mqs->importAndRun(implode("\n", [
            "L100\tLemma_en\t\"water\"",
            "L100\tLEXICAL_CATEGORY\tQ1084",
            "L100\tLANGUAGE\tQ7725",
            "L100-F1\tRep_en\t\"running\"",
            "L100-F1\tGRAMMATICAL_FEATURE\tQ1,Q2",
            "L100-S1\tGloss_en\t\"act of running\"",
        ]));

        foreach ($cmds as $i => $c) {
            $this->assertSame('done', $c->status, "Command $i failed");
        }

        // Lemma → wbeditentity
        $this->assertSame('wbeditentity', $mqs->actionLog[0]->action);
        $this->assertSame('L100', $mqs->actionLog[0]->id);
        $data = json_decode($mqs->actionLog[0]->data, true);
        $this->assertSame('water', $data['lemmas']['en']['value']);

        // Lexical category → wbeditentity
        $this->assertSame('wbeditentity', $mqs->actionLog[1]->action);
        $data = json_decode($mqs->actionLog[1]->data, true);
        $this->assertSame('Q1084', $data['lexicalCategory']);

        // Language → wbeditentity
        $data = json_decode($mqs->actionLog[2]->data, true);
        $this->assertSame('Q7725', $data['language']);

        // Representation → wbleditformelements
        $this->assertSame('wbleditformelements', $mqs->actionLog[3]->action);
        $this->assertSame('L100-F1', $mqs->actionLog[3]->formId);

        // Grammatical feature → wbleditformelements
        $this->assertSame('wbleditformelements', $mqs->actionLog[4]->action);
        $data = json_decode($mqs->actionLog[4]->data, true);
        $this->assertSame(['Q1', 'Q2'], $data['grammaticalFeatures']);

        // Gloss → wbleditsenseelements
        $this->assertSame('wbleditsenseelements', $mqs->actionLog[5]->action);
        $this->assertSame('L100-S1', $mqs->actionLog[5]->senseId);
    }

    // =========================================================================
    //  Lexeme — LANGUAGE keyword must not shadow existing Len (label) syntax
    // =========================================================================

    /**
     * "Len" must still parse as set-label-in-English, not get swallowed by the
     * LANGUAGE handler. The LANGUAGE branch requires an exact keyword match on
     * col[1]; Len matches the older [LADS] regex instead.
     *
     * @group unit
     */
    public function testImportV1_LenStillParsesAsLabel(): void
    {
        $data = "Q42\tLen\t\"Douglas Adams\"";
        $result = $this->qs->exposedImportDataFromV1($data);

        $this->assertSame('OK', $result['status']);
        $this->assertCount(1, $result['data']['commands']);
        $cmd = $result['data']['commands'][0];
        $this->assertSame('add', $cmd['action']);
        $this->assertSame('label', $cmd['what']);
        $this->assertSame('Q42', $cmd['item']);
        $this->assertSame('en', $cmd['language']);
        $this->assertSame('Douglas Adams', $cmd['value']);
    }

    /**
     * Same test but on a lexeme entity — "Len" on L123 is a label, not the
     * LANGUAGE keyword.
     *
     * @group unit
     */
    public function testImportV1_LenOnLexemeIsStillLabel(): void
    {
        $data = "L123\tLen\t\"some label\"";
        $result = $this->qs->exposedImportDataFromV1($data);

        $this->assertSame('OK', $result['status']);
        $this->assertCount(1, $result['data']['commands']);
        $cmd = $result['data']['commands'][0];
        $this->assertSame('label', $cmd['what']);
        $this->assertSame('en', $cmd['language']);
    }

    /**
     * Verify that "Den" is still parsed as set-description-in-English,
     * not confused with any new lexeme keyword.
     *
     * @group unit
     */
    public function testImportV1_DenStillParsesAsDescription(): void
    {
        $data = "Q42\tDen\t\"English author\"";
        $result = $this->qs->exposedImportDataFromV1($data);

        $this->assertSame('OK', $result['status']);
        $this->assertCount(1, $result['data']['commands']);
        $cmd = $result['data']['commands'][0];
        $this->assertSame('description', $cmd['what']);
        $this->assertSame('en', $cmd['language']);
        $this->assertSame('English author', $cmd['value']);
    }

    /**
     * Alias "Aen" must still work.
     *
     * @group unit
     */
    public function testImportV1_AenStillParsesAsAlias(): void
    {
        $data = "Q42\tAen\t\"DNA\"";
        $result = $this->qs->exposedImportDataFromV1($data);

        $this->assertSame('OK', $result['status']);
        $this->assertCount(1, $result['data']['commands']);
        $cmd = $result['data']['commands'][0];
        $this->assertSame('alias', $cmd['what']);
    }

    /**
     * Sitelink "Senwiki" must still work.
     *
     * @group unit
     */
    public function testImportV1_SitelinkStillWorks(): void
    {
        $data = "Q42\tSenwiki\t\"Douglas Adams\"";
        $result = $this->qs->exposedImportDataFromV1($data);

        $this->assertSame('OK', $result['status']);
        $this->assertCount(1, $result['data']['commands']);
        $cmd = $result['data']['commands'][0];
        $this->assertSame('sitelink', $cmd['what']);
        $this->assertSame('enwiki', $cmd['site']);
    }

    // =========================================================================
    //  Lexeme — JSON round-trip (simulates DB storage and retrieval)
    // =========================================================================

    /**
     * Commands are stored as JSON in the command table and decoded with
     * json_decode before being passed to runSingleCommand.  Verify that
     * a CREATE_LEXEME command survives the encode→decode cycle.
     *
     * @group unit
     */
    public function testJsonRoundtrip_CreateLexeme(): void
    {
        $data = "CREATE_LEXEME\tQ7725\tQ1084\ten:\"water\"\tfr:\"eau\"";
        $result = $this->qs->exposedImportDataFromV1($data);
        $cmd = $result['data']['commands'][0];

        // Simulate DB storage: json_encode then json_decode (as object)
        $json = json_encode($cmd);
        $decoded = json_decode($json);

        $this->assertSame('create', $decoded->action);
        $this->assertSame('lexeme', $decoded->type);
        $this->assertSame('Q7725', $decoded->data->language);
        $this->assertSame('Q1084', $decoded->data->lexicalCategory);
        $this->assertSame('water', $decoded->data->lemmas->en->value);
        $this->assertSame('en', $decoded->data->lemmas->en->language);
        $this->assertSame('eau', $decoded->data->lemmas->fr->value);
    }

    /**
     * Verify that ADD_FORM commands survive JSON round-trip, especially that
     * grammaticalFeatures stays an array (not an object).
     *
     * @group unit
     */
    public function testJsonRoundtrip_AddForm(): void
    {
        $data = "L123\tADD_FORM\ten:\"running\"\tQ1,Q2";
        $result = $this->qs->exposedImportDataFromV1($data);
        $cmd = $result['data']['commands'][0];

        $json = json_encode($cmd);
        $decoded = json_decode($json);

        $this->assertSame('create', $decoded->action);
        $this->assertSame('form', $decoded->type);
        $this->assertSame('L123', $decoded->item);
        // grammaticalFeatures must stay an array after decode
        $this->assertIsArray($decoded->data->grammaticalFeatures);
        $this->assertSame('Q1', $decoded->data->grammaticalFeatures[0]);
        $this->assertSame('Q2', $decoded->data->grammaticalFeatures[1]);
        // representations must be a keyed object
        $this->assertSame('running', $decoded->data->representations->en->value);
    }

    /**
     * Verify that ADD_SENSE commands survive JSON round-trip.
     *
     * @group unit
     */
    public function testJsonRoundtrip_AddSense(): void
    {
        $data = "L123\tADD_SENSE\ten:\"act of running\"\tfr:\"action de courir\"";
        $result = $this->qs->exposedImportDataFromV1($data);
        $cmd = $result['data']['commands'][0];

        $json = json_encode($cmd);
        $decoded = json_decode($json);

        $this->assertSame('create', $decoded->action);
        $this->assertSame('sense', $decoded->type);
        $this->assertSame('act of running', $decoded->data->glosses->en->value);
        $this->assertSame('action de courir', $decoded->data->glosses->fr->value);
    }

    /**
     * Verify that GRAMMATICAL_FEATURE value (an array) survives JSON round-trip.
     *
     * @group unit
     */
    public function testJsonRoundtrip_GrammaticalFeature(): void
    {
        $data = "L123-F1\tGRAMMATICAL_FEATURE\tQ1,Q2,Q3";
        $result = $this->qs->exposedImportDataFromV1($data);
        $cmd = $result['data']['commands'][0];

        $json = json_encode($cmd);
        $decoded = json_decode($json);

        $this->assertSame('add', $decoded->action);
        $this->assertSame('grammatical_feature', $decoded->what);
        $this->assertIsArray($decoded->value);
        $this->assertCount(3, $decoded->value);
        $this->assertSame(['Q1', 'Q2', 'Q3'], $decoded->value);
    }

    /**
     * Verify that Lemma_ command survives JSON round-trip.
     *
     * @group unit
     */
    public function testJsonRoundtrip_SetLemma(): void
    {
        $data = "L123\tLemma_en\t\"water\"";
        $result = $this->qs->exposedImportDataFromV1($data);
        $cmd = $result['data']['commands'][0];

        $json = json_encode($cmd);
        $decoded = json_decode($json);

        $this->assertSame('add', $decoded->action);
        $this->assertSame('lemma', $decoded->what);
        $this->assertSame('L123', $decoded->item);
        $this->assertSame('en', $decoded->language);
        $this->assertSame('water', $decoded->value);
    }

    // =========================================================================
    //  Lexeme — compressCommands must not eat lexeme commands
    // =========================================================================

    /**
     * When command compression is on, a CREATE (item) followed by Lxx on LAST
     * gets merged into the CREATE's data block.  But CREATE_LEXEME (type=lexeme)
     * must NOT be merged — the compressor should leave lexeme sequences alone.
     *
     * @group unit
     */
    public function testCompressCommands_LexemeCreateNotMerged(): void
    {
        $data = "CREATE_LEXEME\tQ7725\tQ1084\ten:\"water\"\nLAST\tLemma_fr\t\"eau\"";
        $result = $this->qs->exposedImportDataFromV1($data);
        $commands = $result['data']['commands'];

        $compressed = $this->qs->exposedCompressCommands($commands);

        // Both commands must survive — the compressor must NOT fold the lemma
        // into the CREATE_LEXEME (it only folds into type==item).
        $this->assertCount(2, $compressed);
        $this->assertSame('create', $compressed[0]['action']);
        $this->assertSame('lexeme', $compressed[0]['type']);
        $this->assertSame('add', $compressed[1]['action']);
        $this->assertSame('lemma', $compressed[1]['what']);
    }

    /**
     * Compression must still work for regular CREATE (item) + label.
     * This is a regression guard.
     *
     * @group unit
     */
    public function testCompressCommands_ItemCreateStillMerges(): void
    {
        $data = "CREATE\nLAST\tLen\t\"Test\"";
        $result = $this->qs->exposedImportDataFromV1($data);
        $commands = $result['data']['commands'];

        $compressed = $this->qs->exposedCompressCommands($commands);

        // Should be merged into a single CREATE with data.labels
        $this->assertCount(1, $compressed);
        $this->assertSame('create', $compressed[0]['action']);
        $this->assertSame('item', $compressed[0]['type']);
        $this->assertSame('Test', $compressed[0]['data']['labels']['en']['value']);
    }

    /**
     * A CREATE_LEXEME followed by ADD_FORM on LAST must not be compressed
     * together (they are separate API calls).
     *
     * @group unit
     */
    public function testCompressCommands_LexemeCreateThenFormNotMerged(): void
    {
        $data = "CREATE_LEXEME\tQ7725\tQ1084\ten:\"water\"\nLAST\tADD_FORM\ten:\"waters\"\tQ146786";
        $result = $this->qs->exposedImportDataFromV1($data);
        $commands = $result['data']['commands'];

        $compressed = $this->qs->exposedCompressCommands($commands);

        $this->assertCount(2, $compressed);
        $this->assertSame('lexeme', $compressed[0]['type']);
        $this->assertSame('form', $compressed[1]['type']);
    }

    // =========================================================================
    //  Lexeme — remove-statement on forms and senses
    // =========================================================================

    /**
     * Removing a statement from a form: the leading dash on the entity ID
     * should produce action=remove.
     *
     * @group unit
     */
    public function testImportV1_RemoveStatementOnForm(): void
    {
        $data = "-L123-F1\tP31\tQ5";
        $result = $this->qs->exposedImportDataFromV1($data);

        $this->assertSame('OK', $result['status']);
        $this->assertCount(1, $result['data']['commands']);
        $cmd = $result['data']['commands'][0];
        $this->assertSame('remove', $cmd['action']);
        $this->assertSame('statement', $cmd['what']);
        $this->assertSame('L123-F1', $cmd['item']);
        $this->assertSame('P31', $cmd['property']);
    }

    /**
     * Removing a statement from a sense.
     *
     * @group unit
     */
    public function testImportV1_RemoveStatementOnSense(): void
    {
        $data = "-L123-S1\tP31\tQ5";
        $result = $this->qs->exposedImportDataFromV1($data);

        $this->assertSame('OK', $result['status']);
        $this->assertCount(1, $result['data']['commands']);
        $cmd = $result['data']['commands'][0];
        $this->assertSame('remove', $cmd['action']);
        $this->assertSame('statement', $cmd['what']);
        $this->assertSame('L123-S1', $cmd['item']);
    }

    /**
     * Removing a statement from a lexeme.
     *
     * @group unit
     */
    public function testImportV1_RemoveStatementOnLexeme(): void
    {
        $data = "-L123\tP31\tQ5";
        $result = $this->qs->exposedImportDataFromV1($data);

        $this->assertSame('OK', $result['status']);
        $this->assertCount(1, $result['data']['commands']);
        $cmd = $result['data']['commands'][0];
        $this->assertSame('remove', $cmd['action']);
        $this->assertSame('statement', $cmd['what']);
        $this->assertSame('L123', $cmd['item']);
    }

    // =========================================================================
    //  Lexeme — mixed realistic batch
    // =========================================================================

    /**
     * Parse a realistic multi-line batch that creates a lexeme, adds forms,
     * senses, edits representations, and adds a statement with qualifiers.
     *
     * @group unit
     */
    public function testImportV1_FullLexemeBatch(): void
    {
        $data = implode("\n", [
            "CREATE_LEXEME\tQ7725\tQ1084\ten:\"water\"",
            "LAST\tLemma_fr\t\"eau\"",
            "LAST\tADD_FORM\ten:\"water\"\tQ110786",
            "LAST\tADD_FORM\ten:\"waters\"\tQ146786",
            "LAST\tADD_SENSE\ten:\"transparent liquid\"",
            "LAST\tP5137\tQ3024658",
        ]);
        $result = $this->qs->exposedImportDataFromV1($data);

        $this->assertSame('OK', $result['status']);
        $this->assertCount(6, $result['data']['commands']);

        // 0: CREATE_LEXEME
        $this->assertSame('create', $result['data']['commands'][0]['action']);
        $this->assertSame('lexeme', $result['data']['commands'][0]['type']);
        $this->assertSame('water', $result['data']['commands'][0]['data']['lemmas']['en']['value']);

        // 1: Lemma_fr
        $this->assertSame('lemma', $result['data']['commands'][1]['what']);
        $this->assertSame('LAST', $result['data']['commands'][1]['item']);
        $this->assertSame('fr', $result['data']['commands'][1]['language']);
        $this->assertSame('eau', $result['data']['commands'][1]['value']);

        // 2: ADD_FORM singular
        $this->assertSame('create', $result['data']['commands'][2]['action']);
        $this->assertSame('form', $result['data']['commands'][2]['type']);
        $this->assertSame('LAST', $result['data']['commands'][2]['item']);
        $this->assertSame(['Q110786'], $result['data']['commands'][2]['data']['grammaticalFeatures']);

        // 3: ADD_FORM plural
        $this->assertSame('form', $result['data']['commands'][3]['type']);
        $this->assertSame(['Q146786'], $result['data']['commands'][3]['data']['grammaticalFeatures']);

        // 4: ADD_SENSE
        $this->assertSame('sense', $result['data']['commands'][4]['type']);
        $this->assertSame('transparent liquid', $result['data']['commands'][4]['data']['glosses']['en']['value']);

        // 5: statement
        $this->assertSame('add', $result['data']['commands'][5]['action']);
        $this->assertSame('statement', $result['data']['commands'][5]['what']);
        $this->assertSame('LAST', $result['data']['commands'][5]['item']);
        $this->assertSame('P5137', $result['data']['commands'][5]['property']);
    }

    // =========================================================================
    //  Lexeme — pipe and double-pipe separators
    // =========================================================================

    /**
     * V1 input without tabs falls back to pipe (|) separators.
     * Lexeme commands must work with this fallback too.
     *
     * @group unit
     */
    public function testImportV1_PipeSeparatorCreateLexeme(): void
    {
        $data = "CREATE_LEXEME|Q7725|Q1084|en:\"water\"";
        $result = $this->qs->exposedImportDataFromV1($data);

        $this->assertSame('OK', $result['status']);
        $this->assertCount(1, $result['data']['commands']);
        $cmd = $result['data']['commands'][0];
        $this->assertSame('create', $cmd['action']);
        $this->assertSame('lexeme', $cmd['type']);
        $this->assertSame('water', $cmd['data']['lemmas']['en']['value']);
    }

    /**
     * Double-pipe (||) as line separator with pipe (|) column separator.
     *
     * @group unit
     */
    public function testImportV1_DoublePipeSeparatorLexemeBatch(): void
    {
        $data = "CREATE_LEXEME|Q7725|Q1084|en:\"water\"||LAST|Lemma_fr|\"eau\"";
        $result = $this->qs->exposedImportDataFromV1($data);

        $this->assertSame('OK', $result['status']);
        $this->assertCount(2, $result['data']['commands']);
        $this->assertSame('lexeme', $result['data']['commands'][0]['type']);
        $this->assertSame('lemma', $result['data']['commands'][1]['what']);
        $this->assertSame('eau', $result['data']['commands'][1]['value']);
    }

    /**
     * Pipe separator for ADD_FORM.
     *
     * @group unit
     */
    public function testImportV1_PipeSeparatorAddForm(): void
    {
        $data = "L123|ADD_FORM|en:\"running\"|Q1,Q2";
        $result = $this->qs->exposedImportDataFromV1($data);

        $this->assertSame('OK', $result['status']);
        $this->assertCount(1, $result['data']['commands']);
        $cmd = $result['data']['commands'][0];
        $this->assertSame('form', $cmd['type']);
        $this->assertSame('running', $cmd['data']['representations']['en']['value']);
        $this->assertSame(['Q1', 'Q2'], $cmd['data']['grammaticalFeatures']);
    }

    // =========================================================================
    //  Lexeme — single grammatical feature (edge case)
    // =========================================================================

    /**
     * A single Q-ID for grammatical features (no comma) must produce an
     * array with one element.
     *
     * @group unit
     */
    public function testImportV1_GrammaticalFeatureSingleItem(): void
    {
        $data = "L123-F1\tGRAMMATICAL_FEATURE\tQ1";
        $result = $this->qs->exposedImportDataFromV1($data);

        $this->assertSame('OK', $result['status']);
        $cmd = $result['data']['commands'][0];
        $this->assertSame(['Q1'], $cmd['value']);
    }

    // =========================================================================
    //  Lexeme — case insensitivity of keywords
    // =========================================================================

    /**
     * Keywords like ADD_FORM, ADD_SENSE, LEXICAL_CATEGORY, etc. should be
     * case-insensitive.
     *
     * @group unit
     */
    public function testImportV1_KeywordsCaseInsensitive(): void
    {
        // add_form in lower case
        $data = "L123\tadd_form\ten:\"running\"";
        $result = $this->qs->exposedImportDataFromV1($data);
        $this->assertCount(1, $result['data']['commands']);
        $this->assertSame('form', $result['data']['commands'][0]['type']);

        // add_sense mixed case
        $data = "L123\tAdd_Sense\ten:\"act of running\"";
        $result = $this->qs->exposedImportDataFromV1($data);
        $this->assertCount(1, $result['data']['commands']);
        $this->assertSame('sense', $result['data']['commands'][0]['type']);

        // lexical_category lower
        $data = "L123\tlexical_category\tQ1084";
        $result = $this->qs->exposedImportDataFromV1($data);
        $this->assertCount(1, $result['data']['commands']);
        $this->assertSame('lexical_category', $result['data']['commands'][0]['what']);

        // language mixed
        $data = "L123\tLanguage\tQ7725";
        $result = $this->qs->exposedImportDataFromV1($data);
        $this->assertCount(1, $result['data']['commands']);
        $this->assertSame('language', $result['data']['commands'][0]['what']);

        // grammatical_feature lower
        $data = "L123-F1\tgrammatical_feature\tQ1";
        $result = $this->qs->exposedImportDataFromV1($data);
        $this->assertCount(1, $result['data']['commands']);
        $this->assertSame('grammatical_feature', $result['data']['commands'][0]['what']);
    }

    /**
     * Lemma_, Rep_, Gloss_ prefixes are case-insensitive per the regex /i flag.
     *
     * @group unit
     */
    public function testImportV1_PrefixesCaseInsensitive(): void
    {
        $data = "L123\tLEMMA_en\t\"water\"";
        $result = $this->qs->exposedImportDataFromV1($data);
        $this->assertCount(1, $result['data']['commands']);
        $this->assertSame('lemma', $result['data']['commands'][0]['what']);

        $data = "L123-F1\tREP_en\t\"running\"";
        $result = $this->qs->exposedImportDataFromV1($data);
        $this->assertCount(1, $result['data']['commands']);
        $this->assertSame('representation', $result['data']['commands'][0]['what']);

        $data = "L123-S1\tGLOSS_en\t\"act of running\"";
        $result = $this->qs->exposedImportDataFromV1($data);
        $this->assertCount(1, $result['data']['commands']);
        $this->assertSame('gloss', $result['data']['commands'][0]['what']);
    }

    // =========================================================================
    //  Lexeme — existing CREATE and MERGE are not broken
    // =========================================================================

    /**
     * Plain CREATE (item) must still work.
     *
     * @group unit
     */
    public function testImportV1_CreateItemStillWorks(): void
    {
        $data = "CREATE";
        $result = $this->qs->exposedImportDataFromV1($data);

        $this->assertCount(1, $result['data']['commands']);
        $cmd = $result['data']['commands'][0];
        $this->assertSame('create', $cmd['action']);
        $this->assertSame('item', $cmd['type']);
    }

    /**
     * MERGE must still work.
     *
     * @group unit
     */
    public function testImportV1_MergeStillWorks(): void
    {
        $data = "MERGE\tQ1\tQ2";
        $result = $this->qs->exposedImportDataFromV1($data);

        $this->assertCount(1, $result['data']['commands']);
        $cmd = $result['data']['commands'][0];
        $this->assertSame('merge', $cmd['action']);
    }

    /**
     * CREATE_PROPERTY must still work.
     *
     * @group unit
     */
    public function testImportV1_CreatePropertyStillWorks(): void
    {
        $data = "CREATE_PROPERTY\tstring";
        $result = $this->qs->exposedImportDataFromV1($data);

        $this->assertCount(1, $result['data']['commands']);
        $cmd = $result['data']['commands'][0];
        $this->assertSame('create', $cmd['action']);
        $this->assertSame('property', $cmd['type']);
        $this->assertSame('string', $cmd['data']['datatype']);
    }

    // =========================================================================
    //  Lexeme — ADD_FORM empty representation (error path)
    // =========================================================================

    /**
     * ADD_FORM with no recognizable representation column should still produce
     * a command (with empty representations), not crash.
     *
     * @group unit
     */
    public function testImportV1_AddFormNoRepresentation(): void
    {
        $data = "L123\tADD_FORM\tQ1";
        $result = $this->qs->exposedImportDataFromV1($data);

        $this->assertCount(1, $result['data']['commands']);
        $cmd = $result['data']['commands'][0];
        $this->assertSame('form', $cmd['type']);
        $this->assertEmpty($cmd['data']['representations']);
        $this->assertSame(['Q1'], $cmd['data']['grammaticalFeatures']);
    }

    // =========================================================================
    //  Lexeme — statement with qualifier on a lexeme
    // =========================================================================

    /**
     * Adding a statement WITH a qualifier to a lexeme entity.
     *
     * @group unit
     */
    public function testImportV1_LexemeStatementWithQualifier(): void
    {
        $data = "L123\tP31\tQ5\tP585\t+2024-01-01T00:00:00Z/11";
        $result = $this->qs->exposedImportDataFromV1($data);

        $this->assertSame('OK', $result['status']);
        $this->assertCount(2, $result['data']['commands']);

        $stmt = $result['data']['commands'][0];
        $this->assertSame('statement', $stmt['what']);
        $this->assertSame('L123', $stmt['item']);

        $qual = $result['data']['commands'][1];
        $this->assertSame('qualifier', $qual['what']);
        $this->assertSame('L123', $qual['item']);
        $this->assertSame('P585', $qual['qualifier']['prop']);
    }

    // =========================================================================
    //  Lexeme — create_lexeme in lower case
    // =========================================================================

    /**
     * The first column is uppercased before comparison, so "create_lexeme"
     * in lower case should work.
     *
     * @group unit
     */
    public function testImportV1_CreateLexemeLowerCase(): void
    {
        $data = "create_lexeme\tQ7725\tQ1084\ten:\"water\"";
        $result = $this->qs->exposedImportDataFromV1($data);

        $this->assertCount(1, $result['data']['commands']);
        $cmd = $result['data']['commands'][0];
        $this->assertSame('create', $cmd['action']);
        $this->assertSame('lexeme', $cmd['type']);
    }

    // =========================================================================
    //  LAST_FORM / LAST_SENSE — V1 parsing
    // =========================================================================

    /**
     * LAST_FORM in column 0 must be accepted by the parser and preserved
     * in the command's item field.
     *
     * @group unit
     */
    public function testImportV1_LastFormRepresentation(): void
    {
        $data = "LAST_FORM\tRep_en\t\"running\"";
        $result = $this->qs->exposedImportDataFromV1($data);

        $this->assertSame('OK', $result['status']);
        $this->assertCount(1, $result['data']['commands']);
        $cmd = $result['data']['commands'][0];
        $this->assertSame('add', $cmd['action']);
        $this->assertSame('representation', $cmd['what']);
        $this->assertSame('LAST_FORM', $cmd['item']);
        $this->assertSame('en', $cmd['language']);
        $this->assertSame('running', $cmd['value']);
    }

    /**
     * LAST_FORM with GRAMMATICAL_FEATURE.
     *
     * @group unit
     */
    public function testImportV1_LastFormGrammaticalFeature(): void
    {
        $data = "LAST_FORM\tGRAMMATICAL_FEATURE\tQ1,Q2";
        $result = $this->qs->exposedImportDataFromV1($data);

        $this->assertCount(1, $result['data']['commands']);
        $cmd = $result['data']['commands'][0];
        $this->assertSame('grammatical_feature', $cmd['what']);
        $this->assertSame('LAST_FORM', $cmd['item']);
        $this->assertSame(['Q1', 'Q2'], $cmd['value']);
    }

    /**
     * LAST_FORM with a statement (P31).
     *
     * @group unit
     */
    public function testImportV1_LastFormStatement(): void
    {
        $data = "LAST_FORM\tP31\tQ5";
        $result = $this->qs->exposedImportDataFromV1($data);

        $this->assertCount(1, $result['data']['commands']);
        $cmd = $result['data']['commands'][0];
        $this->assertSame('statement', $cmd['what']);
        $this->assertSame('LAST_FORM', $cmd['item']);
        $this->assertSame('P31', $cmd['property']);
    }

    /**
     * LAST_SENSE in column 0 for a gloss edit.
     *
     * @group unit
     */
    public function testImportV1_LastSenseGloss(): void
    {
        $data = "LAST_SENSE\tGloss_en\t\"act of running\"";
        $result = $this->qs->exposedImportDataFromV1($data);

        $this->assertCount(1, $result['data']['commands']);
        $cmd = $result['data']['commands'][0];
        $this->assertSame('gloss', $cmd['what']);
        $this->assertSame('LAST_SENSE', $cmd['item']);
        $this->assertSame('en', $cmd['language']);
    }

    /**
     * LAST_SENSE with a statement.
     *
     * @group unit
     */
    public function testImportV1_LastSenseStatement(): void
    {
        $data = "LAST_SENSE\tP31\tQ5";
        $result = $this->qs->exposedImportDataFromV1($data);

        $this->assertCount(1, $result['data']['commands']);
        $cmd = $result['data']['commands'][0];
        $this->assertSame('statement', $cmd['what']);
        $this->assertSame('LAST_SENSE', $cmd['item']);
    }

    /**
     * LAST_FORM / LAST_SENSE as values in a statement (column 2).
     *
     * @group unit
     */
    public function testParseValueV1_LastFormAndLastSense(): void
    {
        $cmd = [];
        $result = $this->qs->exposedParseValueV1('LAST_FORM', $cmd);
        $this->assertTrue($result);
        $this->assertSame('wikibase-entityid', $cmd['datavalue']['type']);
        $this->assertSame('LAST_FORM', $cmd['datavalue']['value']['id']);

        $cmd = [];
        $result = $this->qs->exposedParseValueV1('LAST_SENSE', $cmd);
        $this->assertTrue($result);
        $this->assertSame('wikibase-entityid', $cmd['datavalue']['type']);
        $this->assertSame('LAST_SENSE', $cmd['datavalue']['value']['id']);
    }

    /**
     * A realistic multi-line batch using LAST, LAST_FORM, and LAST_SENSE.
     *
     * @group unit
     */
    public function testImportV1_FullBatchWithLastFormAndSense(): void
    {
        $data = implode("\n", [
            "CREATE_LEXEME\tQ7725\tQ1084\ten:\"water\"",
            "LAST\tADD_FORM\ten:\"water\"\tQ110786",
            "LAST_FORM\tRep_fr\t\"eau\"",
            "LAST_FORM\tP31\tQ5",
            "LAST\tADD_SENSE\ten:\"transparent liquid\"",
            "LAST_SENSE\tGloss_fr\t\"liquide transparent\"",
            "LAST_SENSE\tP5137\tQ202368",
            "LAST\tP31\tQ5",
        ]);
        $result = $this->qs->exposedImportDataFromV1($data);

        $this->assertSame('OK', $result['status']);
        $this->assertCount(8, $result['data']['commands']);

        // 0: CREATE_LEXEME
        $this->assertSame('create', $result['data']['commands'][0]['action']);
        $this->assertSame('lexeme', $result['data']['commands'][0]['type']);

        // 1: ADD_FORM on LAST (lexeme)
        $this->assertSame('LAST', $result['data']['commands'][1]['item']);
        $this->assertSame('form', $result['data']['commands'][1]['type']);

        // 2: Rep_fr on LAST_FORM
        $this->assertSame('LAST_FORM', $result['data']['commands'][2]['item']);
        $this->assertSame('representation', $result['data']['commands'][2]['what']);

        // 3: statement on LAST_FORM
        $this->assertSame('LAST_FORM', $result['data']['commands'][3]['item']);
        $this->assertSame('statement', $result['data']['commands'][3]['what']);

        // 4: ADD_SENSE on LAST (lexeme)
        $this->assertSame('LAST', $result['data']['commands'][4]['item']);
        $this->assertSame('sense', $result['data']['commands'][4]['type']);

        // 5: Gloss_fr on LAST_SENSE
        $this->assertSame('LAST_SENSE', $result['data']['commands'][5]['item']);
        $this->assertSame('gloss', $result['data']['commands'][5]['what']);

        // 6: statement on LAST_SENSE
        $this->assertSame('LAST_SENSE', $result['data']['commands'][6]['item']);
        $this->assertSame('statement', $result['data']['commands'][6]['what']);

        // 7: statement on LAST (lexeme)
        $this->assertSame('LAST', $result['data']['commands'][7]['item']);
        $this->assertSame('statement', $result['data']['commands'][7]['what']);
    }

    // =========================================================================
    //  LAST_FORM / LAST_SENSE — isLastKeyword helper
    // =========================================================================

    /**
     * @group unit
     */
    public function testIsLastKeyword(): void
    {
        $this->assertTrue($this->qs->exposedIsLastKeyword('LAST'));
        $this->assertTrue($this->qs->exposedIsLastKeyword('LAST_FORM'));
        $this->assertTrue($this->qs->exposedIsLastKeyword('LAST_SENSE'));
        $this->assertFalse($this->qs->exposedIsLastKeyword('Q42'));
        $this->assertFalse($this->qs->exposedIsLastKeyword('LAST_PROPERTY'));
        $this->assertFalse($this->qs->exposedIsLastKeyword(''));
    }

    // =========================================================================
    //  LAST_FORM / LAST_SENSE — batch state encoding / decoding
    // =========================================================================

    /**
     * When only last_item is set, encodeLastState returns a plain string
     * (backward compatible with old batches).
     *
     * @group unit
     */
    public function testEncodeLastState_PlainItem(): void
    {
        $this->qs->last_item = 'Q42';
        $this->qs->last_form = '';
        $this->qs->last_sense = '';
        $this->assertSame('Q42', $this->qs->exposedEncodeLastState());
    }

    /**
     * When last_form or last_sense are set, the encoded value is pipe-delimited.
     *
     * @group unit
     */
    public function testEncodeLastState_WithFormAndSense(): void
    {
        $this->qs->last_item = 'L100';
        $this->qs->last_form = 'L100-F1';
        $this->qs->last_sense = 'L100-S1';
        $this->assertSame('L100|L100-F1|L100-S1', $this->qs->exposedEncodeLastState());
    }

    /**
     * When only last_form is set (no sense yet).
     *
     * @group unit
     */
    public function testEncodeLastState_FormOnly(): void
    {
        $this->qs->last_item = 'L100';
        $this->qs->last_form = 'L100-F1';
        $this->qs->last_sense = '';
        $this->assertSame('L100|L100-F1|', $this->qs->exposedEncodeLastState());
    }

    /**
     * Decoding a plain string (from old batches) sets last_item and
     * clears last_form / last_sense.
     *
     * @group unit
     */
    public function testDecodeLastState_PlainItem(): void
    {
        $this->qs->exposedDecodeLastState('Q42');
        $this->assertSame('Q42', $this->qs->last_item);
        $this->assertSame('', $this->qs->last_form);
        $this->assertSame('', $this->qs->last_sense);
    }

    /**
     * Decoding a pipe-delimited string restores all three.
     *
     * @group unit
     */
    public function testDecodeLastState_WithFormAndSense(): void
    {
        $this->qs->exposedDecodeLastState('L100|L100-F1|L100-S1');
        $this->assertSame('L100', $this->qs->last_item);
        $this->assertSame('L100-F1', $this->qs->last_form);
        $this->assertSame('L100-S1', $this->qs->last_sense);
    }

    /**
     * Decoding with only form (empty sense part).
     *
     * @group unit
     */
    public function testDecodeLastState_FormOnly(): void
    {
        $this->qs->exposedDecodeLastState('L100|L100-F1|');
        $this->assertSame('L100', $this->qs->last_item);
        $this->assertSame('L100-F1', $this->qs->last_form);
        $this->assertSame('', $this->qs->last_sense);
    }

    /**
     * Round-trip: encode → decode must restore the same state.
     *
     * @group unit
     */
    public function testEncodeDecodeLastState_Roundtrip(): void
    {
        $this->qs->last_item = 'L200';
        $this->qs->last_form = 'L200-F3';
        $this->qs->last_sense = 'L200-S2';
        $encoded = $this->qs->exposedEncodeLastState();

        // Reset and decode
        $this->qs->last_item = '';
        $this->qs->last_form = '';
        $this->qs->last_sense = '';
        $this->qs->exposedDecodeLastState($encoded);

        $this->assertSame('L200', $this->qs->last_item);
        $this->assertSame('L200-F3', $this->qs->last_form);
        $this->assertSame('L200-S2', $this->qs->last_sense);
    }

    // =========================================================================
    //  LAST_FORM / LAST_SENSE — execution with MockableQuickStatements
    // =========================================================================

    /**
     * Full realistic batch: CREATE_LEXEME, ADD_FORM, edit the form via
     * LAST_FORM, ADD_SENSE, edit the sense via LAST_SENSE, then a final
     * statement on LAST (the lexeme).
     *
     * @group unit
     */
    public function testLastFormSense_FullBatchExecution(): void
    {
        $mqs = new MockableQuickStatements();
        $cmds = $mqs->importAndRun(implode("\n", [
            "CREATE_LEXEME\tQ7725\tQ1084\ten:\"water\"",
            "LAST\tADD_FORM\ten:\"water\"\tQ110786",
            "LAST_FORM\tRep_fr\t\"eau\"",
            "LAST_FORM\tGRAMMATICAL_FEATURE\tQ1,Q2",
            "LAST\tADD_SENSE\ten:\"transparent liquid\"",
            "LAST_SENSE\tGloss_fr\t\"liquide transparent\"",
            "LAST_SENSE\tP5137\tQ202368",
            "LAST\tP31\tQ5",
        ]));

        // All must succeed
        foreach ($cmds as $i => $c) {
            $this->assertSame('done', $c->status, "Command $i failed: " . ($c->message ?? ''));
        }

        $lexemeId = $cmds[0]->item;
        $this->assertMatchesRegularExpression('/^L\d+$/', $lexemeId);

        // 1: ADD_FORM → form was added to the lexeme
        $formId = $cmds[1]->item;
        $this->assertMatchesRegularExpression('/^L\d+-F\d+$/', $formId);
        $this->assertSame('wbladdform', $mqs->actionLog[1]->action);
        $this->assertSame($lexemeId, $mqs->actionLog[1]->lexemeId);

        // 2: Rep_fr on LAST_FORM → wbleditformelements with the form ID
        $this->assertSame('wbleditformelements', $mqs->actionLog[2]->action);
        $this->assertSame($formId, $mqs->actionLog[2]->formId);

        // 3: GRAMMATICAL_FEATURE on LAST_FORM
        $this->assertSame('wbleditformelements', $mqs->actionLog[3]->action);
        $this->assertSame($formId, $mqs->actionLog[3]->formId);

        // 4: ADD_SENSE → sense was added to the lexeme (not the form)
        $senseId = $cmds[4]->item;
        $this->assertMatchesRegularExpression('/^L\d+-S\d+$/', $senseId);
        $this->assertSame('wbladdsense', $mqs->actionLog[4]->action);
        $this->assertSame($lexemeId, $mqs->actionLog[4]->lexemeId);

        // 5: Gloss_fr on LAST_SENSE → wbleditsenseelements with the sense ID
        $this->assertSame('wbleditsenseelements', $mqs->actionLog[5]->action);
        $this->assertSame($senseId, $mqs->actionLog[5]->senseId);

        // 6: statement on LAST_SENSE
        $this->assertSame($senseId, $cmds[6]->item);

        // 7: statement on LAST → must be the lexeme, not form or sense
        $this->assertSame($lexemeId, $cmds[7]->item);

        // last_item is the lexeme throughout
        $this->assertSame($lexemeId, $mqs->getLastItem());
    }

    /**
     * Multiple ADD_FORMs followed by LAST_FORM: LAST_FORM tracks the most
     * recently created form.
     *
     * @group unit
     */
    public function testLastForm_TracksLatestForm(): void
    {
        $mqs = new MockableQuickStatements();
        $cmds = $mqs->importAndRun(implode("\n", [
            "CREATE_LEXEME\tQ7725\tQ1084\ten:\"water\"",
            "LAST\tADD_FORM\ten:\"water\"\tQ110786",
            "LAST\tADD_FORM\ten:\"waters\"\tQ146786",
            "LAST_FORM\tRep_fr\t\"eaux\"",
        ]));

        foreach ($cmds as $i => $c) {
            $this->assertSame('done', $c->status, "Command $i failed: " . ($c->message ?? ''));
        }

        $form1 = $cmds[1]->item;
        $form2 = $cmds[2]->item;
        $this->assertNotSame($form1, $form2);

        // LAST_FORM should have resolved to the second form
        $this->assertSame('wbleditformelements', $mqs->actionLog[3]->action);
        $this->assertSame($form2, $mqs->actionLog[3]->formId);
    }

    /**
     * Multiple ADD_SENSEs followed by LAST_SENSE: LAST_SENSE tracks the
     * most recently created sense.
     *
     * @group unit
     */
    public function testLastSense_TracksLatestSense(): void
    {
        $mqs = new MockableQuickStatements();
        $cmds = $mqs->importAndRun(implode("\n", [
            "CREATE_LEXEME\tQ7725\tQ1084\ten:\"water\"",
            "LAST\tADD_SENSE\ten:\"transparent liquid\"",
            "LAST\tADD_SENSE\ten:\"body of water\"",
            "LAST_SENSE\tGloss_fr\t\"étendue d'eau\"",
        ]));

        foreach ($cmds as $i => $c) {
            $this->assertSame('done', $c->status, "Command $i failed: " . ($c->message ?? ''));
        }

        $sense1 = $cmds[1]->item;
        $sense2 = $cmds[2]->item;
        $this->assertNotSame($sense1, $sense2);

        // LAST_SENSE should have resolved to the second sense
        $this->assertSame('wbleditsenseelements', $mqs->actionLog[3]->action);
        $this->assertSame($sense2, $mqs->actionLog[3]->senseId);
    }

    /**
     * CREATE (plain item) must not interfere with LAST_FORM / LAST_SENSE.
     * After CREATE, last_form and last_sense should be cleared.
     *
     * @group unit
     */
    public function testLastFormSense_ClearedAfterCreateItem(): void
    {
        $mqs = new MockableQuickStatements();

        // First create a lexeme with form and sense
        $cmds = $mqs->importAndRun(implode("\n", [
            "CREATE_LEXEME\tQ7725\tQ1084\ten:\"water\"",
            "LAST\tADD_FORM\ten:\"water\"\tQ110786",
            "LAST\tADD_SENSE\ten:\"transparent liquid\"",
        ]));
        $this->assertNotSame('', $mqs->last_form);
        $this->assertNotSame('', $mqs->last_sense);

        // Now create a plain item — should clear form/sense
        $cmds2 = $mqs->importAndRun("CREATE");
        $this->assertSame('done', $cmds2[0]->status);
        $this->assertSame('', $mqs->last_form);
        $this->assertSame('', $mqs->last_sense);
    }

    /**
     * LAST_FORM without a preceding ADD_FORM must produce an error,
     * not crash.
     *
     * @group unit
     */
    public function testLastForm_ErrorWhenNoPrecedingForm(): void
    {
        $mqs = new MockableQuickStatements();
        $cmds = $mqs->importAndRun("LAST_FORM\tRep_en\t\"test\"");

        $this->assertSame('error', $cmds[0]->status);
    }

    /**
     * LAST_SENSE without a preceding ADD_SENSE must produce an error.
     *
     * @group unit
     */
    public function testLastSense_ErrorWhenNoPrecedingSense(): void
    {
        $mqs = new MockableQuickStatements();
        $cmds = $mqs->importAndRun("LAST_SENSE\tGloss_en\t\"test\"");

        $this->assertSame('error', $cmds[0]->status);
    }
}
