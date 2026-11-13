<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Telemetry;

final readonly class DroppedTrackedEvent
{
    /**
     * @param list<TrackedEventWarning> $warnings
     */
    public function __construct(public array $warnings) {}
}
