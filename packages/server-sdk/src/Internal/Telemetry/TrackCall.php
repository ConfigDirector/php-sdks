<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Telemetry;

use ConfigDirector\Context;

final readonly class TrackCall
{
    /**
     * @param array<array-key, mixed>|null $details
     */
    public function __construct(
        public string $eventName,
        public ?Context $context,
        public ?float $value,
        public ?array $details,
    ) {}
}
