<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Time;

final readonly class ClientLifetime
{
    private int $startedAtMonotonicMilliseconds;

    public function __construct(
        private Clock $clock,
        private int $flushIntervalMilliseconds,
    ) {
        $this->startedAtMonotonicMilliseconds = $clock->monotonicMilliseconds();
    }

    public function showsALongRunningProcess(): bool
    {
        $ageMilliseconds = $this->clock->monotonicMilliseconds() - $this->startedAtMonotonicMilliseconds;

        return $ageMilliseconds > $this->flushIntervalMilliseconds;
    }
}
