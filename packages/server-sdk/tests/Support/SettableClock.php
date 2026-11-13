<?php

declare(strict_types=1);

namespace ConfigDirector\Tests\Support;

use ConfigDirector\Internal\Time\Clock;

final class SettableClock implements Clock
{
    private int $millisecondsTheWallClockWasSetBack = 0;

    public function __construct(public int $epochMilliseconds) {}

    public function setTheWallClockBackBy(int $milliseconds): void
    {
        $this->epochMilliseconds -= $milliseconds;
        $this->millisecondsTheWallClockWasSetBack += $milliseconds;
    }

    #[\Override]
    public function epochMilliseconds(): int
    {
        return $this->epochMilliseconds;
    }

    #[\Override]
    public function monotonicMilliseconds(): int
    {
        return $this->epochMilliseconds + $this->millisecondsTheWallClockWasSetBack;
    }
}
