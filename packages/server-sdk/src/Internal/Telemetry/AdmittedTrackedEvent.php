<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Telemetry;

use ConfigDirector\Context;

final readonly class AdmittedTrackedEvent
{
    /**
     * @param list<TrackedEventWarning> $warnings
     */
    public function __construct(
        public string $eventName,
        public ?Context $context,
        public ?float $value,
        public ?string $detailsJson,
        public array $warnings,
    ) {}
}
