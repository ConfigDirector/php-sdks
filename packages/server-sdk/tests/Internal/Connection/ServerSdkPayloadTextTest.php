<?php

declare(strict_types=1);

namespace ConfigDirector\Tests\Internal\Connection;

use ConfigDirector\Internal\Connection\ServerSdkPayloadText;
use PHPUnit\Framework\TestCase;

final class ServerSdkPayloadTextTest extends TestCase
{
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

    public function testAnAnswerThatNeedsMoreMemoryThanTheProcessHasLeftHoldsNoPayload(): void
    {
        $answer = '{"configs":{},"padding":[' . implode(',', array_fill(0, 540_000, '{"a":1}')) . ']}';
        ini_set('memory_limit', (string) (memory_get_usage() + 64 * 1_048_576));

        $payloadOrWhyItHoldsNone = ServerSdkPayloadText::payloadOrWhyItHoldsNone($answer);

        self::assertIsString($payloadOrWhyItHoldsNone);
    }
}
