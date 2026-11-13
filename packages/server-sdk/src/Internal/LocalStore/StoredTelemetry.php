<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\LocalStore;

final readonly class StoredTelemetry
{
    /**
     * @param list<string> $telemetryFileLines
     */
    public function __construct(
        public array $telemetryFileLines,
        public int $droppedEvaluations,
        public int $droppedCapturedContexts,
    ) {}
}
