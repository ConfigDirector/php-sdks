<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Polling;

final readonly class PollingInterval
{
    private const int MINIMUM_SECONDS = 60;

    private function __construct(
        public int $seconds,
        public bool $isRaisedToTheMinimum,
    ) {}

    public static function ofConfiguredSeconds(int $configuredSeconds): self
    {
        return new self(max($configuredSeconds, self::MINIMUM_SECONDS), $configuredSeconds < self::MINIMUM_SECONDS);
    }

    public function milliseconds(): int
    {
        return $this->seconds * 1_000;
    }
}
