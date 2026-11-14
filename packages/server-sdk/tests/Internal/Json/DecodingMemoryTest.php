<?php

declare(strict_types=1);

namespace ConfigDirector\Tests\Internal\Json;

use ConfigDirector\Internal\Json\DecodingMemory;
use PHPUnit\Framework\TestCase;

final class DecodingMemoryTest extends TestCase
{
    private const int MEBIBYTE = 1_048_576;

    private string $memoryLimitBeforeTheTest;

    #[\Override]
    protected function setUp(): void
    {
        $this->memoryLimitBeforeTheTest = (string) ini_get('memory_limit');
    }

    #[\Override]
    protected function tearDown(): void
    {
        ini_set('memory_limit', $this->memoryLimitBeforeTheTest);
    }

    public function testFourMebibytesOfSmallJsonObjectsAreTooMuchForAProcessWith64MebibytesLeft(): void
    {
        $jsonOfSmallObjects = '[' . implode(',', array_fill(0, 540_000, '{"a":1}')) . ']';
        ini_set('memory_limit', (string) (memory_get_usage() + 64 * self::MEBIBYTE));

        $isEnough = DecodingMemory::isEnoughFor($jsonOfSmallObjects);

        self::assertGreaterThan(4 * self::MEBIBYTE, strlen($jsonOfSmallObjects));
        self::assertFalse($isEnough);
    }

    public function testFourMebibytesOfOneJsonStringFitAProcessWith64MebibytesLeft(): void
    {
        $jsonOfOneString = '"' . str_repeat('a', 4 * self::MEBIBYTE) . '"';
        ini_set('memory_limit', (string) (memory_get_usage() + 64 * self::MEBIBYTE));

        $isEnough = DecodingMemory::isEnoughFor($jsonOfOneString);

        self::assertTrue($isEnough);
    }

    public function testAPayloadOf200ConfigsFitsAProcessWith64MebibytesLeft(): void
    {
        $config = '"config-%d":{"id":"id-%d","key":"config-%d","type":"string","target":{"rules":[{"id":"rule-%d",'
            . '"order":0,"type":"conditional","target":"value","value":"a","valueId":"value-a","conditions":'
            . '[{"kind":"attribute","id":"condition-%d","attribute":"traits","trait":"/plan","operator":"equals",'
            . '"targetType":"text","targetValues":["pro"]}]}],"defaultValue":"b","defaultValueId":"value-b"}}';
        $configs = [];
        for ($number = 1; $number <= 200; $number++) {
            $configs[] = sprintf($config, $number, $number, $number, $number, $number);
        }
        $jsonOf200Configs = '{"configs":{' . implode(',', $configs) . '}}';
        ini_set('memory_limit', (string) (memory_get_usage() + 64 * self::MEBIBYTE));

        $isEnough = DecodingMemory::isEnoughFor($jsonOf200Configs);

        self::assertTrue($isEnough);
    }

    public function testFourMebibytesOfConfigsWhoseValuesAreJsonTextFitAProcessWith64MebibytesLeft(): void
    {
        $jsonValueOf20Kilobytes = json_encode(
            ['limits' => array_fill(0, 400, ['daily' => 100, 'burst' => 20, 'regions' => ['us', 'eu']])],
            JSON_THROW_ON_ERROR,
        );
        $configs = [];
        for ($number = 1; $number <= 200; $number++) {
            $configs["config-{$number}"] = [
                'id' => "id-{$number}",
                'key' => "config-{$number}",
                'type' => 'json',
                'target' => ['rules' => [], 'defaultValue' => $jsonValueOf20Kilobytes, 'defaultValueId' => 'value-1'],
            ];
        }
        $jsonOfConfigsWithJsonValues = json_encode(['configs' => $configs], JSON_THROW_ON_ERROR);
        ini_set('memory_limit', (string) (memory_get_usage() + 64 * self::MEBIBYTE));

        $isEnough = DecodingMemory::isEnoughFor($jsonOfConfigsWithJsonValues);

        self::assertGreaterThan(4 * self::MEBIBYTE, strlen($jsonOfConfigsWithJsonValues));
        self::assertTrue($isEnough);
    }

    public function testOneJsonObjectOfAMillionMembersIsTooMuchForAProcessWith128MebibytesLeft(): void
    {
        $jsonOfOneLargeObject = '{' . str_repeat('"member":1,', 1_100_000) . '"member":1}';
        ini_set('memory_limit', (string) (memory_get_usage() + 128 * self::MEBIBYTE));

        $isEnough = DecodingMemory::isEnoughFor($jsonOfOneLargeObject);

        self::assertFalse($isEnough);
    }

    public function testEightMegabytesOfAStringThatNeverEndsAreJudgedWithinAQuarterOfASecond(): void
    {
        $jsonOfAStringThatNeverEnds = '{"configs":{},"padding":"' . str_repeat('\\"', 4_000_000);
        ini_set('memory_limit', (string) (memory_get_usage() + 64 * self::MEBIBYTE));

        $startedAt = hrtime(true);
        DecodingMemory::isEnoughFor($jsonOfAStringThatNeverEnds);
        $millisecondsTaken = intdiv(hrtime(true) - $startedAt, 1_000_000);

        self::assertLessThan(250, $millisecondsTaken);
    }

    public function testFourMebibytesOfSmallJsonObjectsFitAProcessWithoutAMemoryLimit(): void
    {
        $jsonOfSmallObjects = '[' . implode(',', array_fill(0, 540_000, '{"a":1}')) . ']';
        ini_set('memory_limit', '-1');

        $isEnough = DecodingMemory::isEnoughFor($jsonOfSmallObjects);

        self::assertTrue($isEnough);
    }
}
