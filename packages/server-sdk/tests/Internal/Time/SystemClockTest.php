<?php

declare(strict_types=1);

namespace ConfigDirector\Tests\Internal\Time;

use ConfigDirector\Internal\Time\SystemClock;
use PHPUnit\Framework\TestCase;

final class SystemClockTest extends TestCase
{
    public function testTheSystemClockTellsTheTimeSinceTheUnixEpochInMilliseconds(): void
    {
        $secondsBefore = time();

        $epochMilliseconds = (new SystemClock())->epochMilliseconds();

        self::assertGreaterThanOrEqual($secondsBefore * 1_000, $epochMilliseconds);
        self::assertLessThan((time() + 1) * 1_000, $epochMilliseconds);
    }

    public function testTheMonotonicReadingOfTheSystemClockAdvancesByTheMillisecondsThatPass(): void
    {
        $clock = new SystemClock();
        $readingBefore = $clock->monotonicMilliseconds();

        usleep(50_000);
        $millisecondsPassed = $clock->monotonicMilliseconds() - $readingBefore;

        self::assertGreaterThanOrEqual(50, $millisecondsPassed);
        self::assertLessThan(2_000, $millisecondsPassed);
    }
}
