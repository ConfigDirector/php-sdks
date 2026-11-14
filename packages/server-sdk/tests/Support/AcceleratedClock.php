<?php

declare(strict_types=1);

namespace ConfigDirector\Tests\Support;

use ConfigDirector\Internal\Time\Clock;

final readonly class AcceleratedClock implements Clock
{
    public function __construct(
        private int $startedAtEpochMilliseconds,
        private int $timesFasterThanRealTime,
    ) {}

    #[\Override]
    public function epochMilliseconds(): int
    {
        $realMillisecondsSinceItStarted = microtime(true) * 1_000 - $this->startedAtEpochMilliseconds;

        return $this->startedAtEpochMilliseconds
            + (int) floor($realMillisecondsSinceItStarted * $this->timesFasterThanRealTime);
    }

    #[\Override]
    public function monotonicMilliseconds(): int
    {
        return $this->epochMilliseconds();
    }
}
