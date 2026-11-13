<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Time;

final class SystemClock implements Clock
{
    #[\Override]
    public function epochMilliseconds(): int
    {
        return (int) floor(microtime(true) * 1_000);
    }

    #[\Override]
    public function monotonicMilliseconds(): int
    {
        return intdiv(hrtime(true), 1_000_000);
    }
}
