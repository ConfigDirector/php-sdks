<?php

declare(strict_types=1);

namespace ConfigDirector\Tests\Internal\Polling;

use ConfigDirector\Internal\Polling\RetryAfterHeader;
use PHPUnit\Framework\TestCase;

final class RetryAfterHeaderTest extends TestCase
{
    public function testAValueHoldingANulByteNamesNoWait(): void
    {
        $header = RetryAfterHeader::in("Retry-After: Wed, 21 Oct\0 2026 07:28:00 GMT\r\n");

        self::assertNotNull($header);
        self::assertNull($header->secondsToWaitFrom(nowEpochMilliseconds: 0));
    }
}
