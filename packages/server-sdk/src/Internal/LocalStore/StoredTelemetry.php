<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\LocalStore;

final readonly class StoredTelemetry
{
    public function __construct(
        private ?LinesMovedAside $telemetryFileLines,
        private ?LinesMovedAside $droppedCountsFileLines,
    ) {}

    /**
     * @return iterable<int, non-empty-string>
     */
    public function telemetryFileLinesReadOnce(): iterable
    {
        return $this->telemetryFileLines?->linesReadOnce() ?? [];
    }

    public function droppedCountsReadOnce(): DroppedTelemetryCounts
    {
        $droppedCounts = new DroppedTelemetryCounts(evaluations: 0, capturedContexts: 0);
        foreach ($this->droppedCountsFileLines?->linesReadOnce() ?? [] as $droppedCountsFileLine) {
            $droppedCounts = $droppedCounts->plus(
                DroppedTelemetryCounts::fromJson($droppedCountsFileLine)
                    ?? new DroppedTelemetryCounts(evaluations: 0, capturedContexts: 0),
            );
        }

        return $droppedCounts;
    }
}
