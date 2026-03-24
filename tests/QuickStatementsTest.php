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

    public function validItemIdentifierProvider(): array
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

    public function invalidItemIdentifierProvider(): array
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
}
