<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Telemetry;

final class CollectedTelemetry
{
    private const int JSON_FLAGS = JSON_THROW_ON_ERROR
        | JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_INVALID_UTF8_SUBSTITUTE;

    /** @var NewestEntries<int> */
    private NewestEntries $evaluationCountsByEventJson;

    /** @var NewestEntries<string> */
    private NewestEntries $capturedContextJsonsByKindAndIdentifier;

    private InvalidContextSubmissions $invalidContextSubmissions;

    private TrackedEventQueue $trackedEventQueue;

    private int $droppedEvaluations = 0;

    private int $droppedCapturedContexts = 0;

    public function __construct(int $eventQueueLimit, private int $collectingSinceEpochMilliseconds)
    {
        $this->evaluationCountsByEventJson = new NewestEntries(intdiv($eventQueueLimit * 7 + 9, 10));
        $this->capturedContextJsonsByKindAndIdentifier = new NewestEntries(intdiv($eventQueueLimit * 3, 10));
        $this->invalidContextSubmissions = new InvalidContextSubmissions();
        $this->trackedEventQueue = new TrackedEventQueue();
    }

    public function countEvaluations(string $eventJson, int $count): void
    {
        $countSoFar = $this->evaluationCountsByEventJson->entryWithKey($eventJson) ?? 0;
        $this->droppedEvaluations += $this->evaluationCountsByEventJson->oldestEntryLeftOutByPutting(
            $eventJson,
            $countSoFar + $count,
        ) ?? 0;
    }

    public function captureContext(string $kindAndIdentifier, string $capturedContextJson): void
    {
        $contextLeftOut = $this->capturedContextJsonsByKindAndIdentifier->oldestEntryLeftOutByPutting(
            $kindAndIdentifier,
            $capturedContextJson,
        );
        if ($contextLeftOut !== null) {
            $this->droppedCapturedContexts++;
        }
    }

    public function countInvalidContexts(?string $kindSample, int $count): InvalidContextKindRecording
    {
        return $this->invalidContextSubmissions->countSubmissions($kindSample, $count);
    }

    /**
     * @return list<TrackedEventWarning>
     */
    public function keepTrackedEvent(
        AdmittedTrackedEvent|DroppedTrackedEvent $admission,
        int $happenedAtEpochMilliseconds,
    ): array {
        return $this->trackedEventQueue->keepTrackedEvent($admission, $happenedAtEpochMilliseconds);
    }

    public function trackedEventsLeftOut(): int
    {
        return $this->trackedEventQueue->entriesLeftOut();
    }

    public function countAsDropped(int $evaluations, int $capturedContexts): void
    {
        $this->droppedEvaluations += $evaluations;
        $this->droppedCapturedContexts += $capturedContexts;
    }

    public function isEmpty(): bool
    {
        return $this->evaluationsCollected() === 0
            && $this->capturedContextsCollected() === 0
            && $this->invalidContextSubmissions->countsByKindSample() === []
            && $this->trackedEventQueue->entryJsons() === [];
    }

    public function evaluationsCollected(): int
    {
        return array_sum($this->evaluationCountsByEventJson->entriesByKey()) + $this->droppedEvaluations;
    }

    public function capturedContextsCollected(): int
    {
        return count($this->capturedContextJsonsByKindAndIdentifier->entriesByKey())
            + $this->droppedCapturedContexts;
    }

    public function collectingSinceEpochMilliseconds(): int
    {
        return $this->collectingSinceEpochMilliseconds;
    }

    public function telemetryFileLine(): string
    {
        $invalidContextSubmissions = $this->invalidContextSubmissions;

        return json_encode(
            [
                'collectingSince' => $this->collectingSinceEpochMilliseconds,
                'evaluationCounts' => $this->evaluationCountsByEventJson->entriesByKey(),
                'capturedContexts' => $this->capturedContextJsonsByKindAndIdentifier->entriesByKey(),
                'invalidContextCounts' => $invalidContextSubmissions->countsByKindSample(),
                'invalidContextCountBeyondTheSampleLimit' => $invalidContextSubmissions->countBeyondTheSampleLimit(),
                'droppedEvaluations' => $this->droppedEvaluations,
                'droppedCapturedContexts' => $this->droppedCapturedContexts,
                'trackedEvents' => $this->trackedEventQueue->entryJsons(),
            ],
            self::JSON_FLAGS,
        );
    }

    public function addTelemetryOfTheFileLine(string $telemetryFileLine): void
    {
        try {
            $telemetryOfAClient = json_decode($telemetryFileLine, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return;
        }
        $collectingSince = is_array($telemetryOfAClient) ? $telemetryOfAClient['collectingSince'] ?? null : null;
        if (!is_array($telemetryOfAClient) || !is_int($collectingSince)) {
            return;
        }

        $this->collectingSinceEpochMilliseconds = min($this->collectingSinceEpochMilliseconds, $collectingSince);
        foreach (self::countsIn($telemetryOfAClient['evaluationCounts'] ?? null) as $eventJson => $count) {
            if (self::isAJsonObject((string) $eventJson)) {
                $this->countEvaluations((string) $eventJson, $count);
            }
        }
        $capturedContexts = $telemetryOfAClient['capturedContexts'] ?? null;
        foreach (is_array($capturedContexts) ? $capturedContexts : [] as $kindAndIdentifier => $capturedContextJson) {
            if (is_string($capturedContextJson) && self::isAJsonObject($capturedContextJson)) {
                $this->captureContext((string) $kindAndIdentifier, $capturedContextJson);
            }
        }
        foreach (self::countsIn($telemetryOfAClient['invalidContextCounts'] ?? null) as $kindSample => $count) {
            $this->countInvalidContexts((string) $kindSample, $count);
        }
        $countBeyondTheSampleLimit = $telemetryOfAClient['invalidContextCountBeyondTheSampleLimit'] ?? null;
        foreach (self::countsIn([$countBeyondTheSampleLimit]) as $count) {
            $this->countInvalidContexts(null, $count);
        }
        $trackedEvents = $telemetryOfAClient['trackedEvents'] ?? null;
        foreach (is_array($trackedEvents) ? $trackedEvents : [] as $entryJson) {
            if (is_string($entryJson) && self::isAJsonObject($entryJson)) {
                $this->trackedEventQueue->keepEntryUnlessFull($entryJson);
            }
        }
        $this->countAsDropped(
            array_sum(self::countsIn([$telemetryOfAClient['droppedEvaluations'] ?? null])),
            array_sum(self::countsIn([$telemetryOfAClient['droppedCapturedContexts'] ?? null])),
        );
    }

    /**
     * @param array<string, string> $metaContext
     */
    public function reportJson(string $serverSdkKey, array $metaContext, int $reportedAtEpochMilliseconds): string
    {
        $startTime = ReportedTime::of($this->collectingSinceEpochMilliseconds);
        $endTime = ReportedTime::of($reportedAtEpochMilliseconds);
        $aggregatedEntryJson = static fn(int $count, string $eventJson): string => sprintf(
            '{"startTime":"%s","endTime":"%s","count":%d,"event":%s}',
            $startTime,
            $endTime,
            $count,
            $eventJson,
        );

        $evaluatedConfig = [];
        foreach ($this->evaluationCountsByEventJson->entriesByKey() as $eventJson => $count) {
            $evaluatedConfig[] = $aggregatedEntryJson($count, $eventJson);
        }
        $invalidContext = [];
        foreach ($this->invalidContextSubmissions->countsByKindSample() as $kindSample => $count) {
            $invalidContext[] = $aggregatedEntryJson($count, json_encode(
                ['reason' => 'invalid-context-kind', 'kindSample' => (string) $kindSample],
                self::JSON_FLAGS,
            ));
        }
        if ($this->invalidContextSubmissions->countBeyondTheSampleLimit() > 0) {
            $invalidContext[] = $aggregatedEntryJson(
                $this->invalidContextSubmissions->countBeyondTheSampleLimit(),
                '{"reason":"invalid-context-kind"}',
            );
        }

        return sprintf(
            '{"serverSdkKey":%s,"metaContext":%s,"discreteEvents":{"capturedContexts":[%s],"trackedEvents":[%s]},'
            . '"aggregatedEvents":{"evaluatedConfig":[%s],"invalidContext":[%s]},'
            . '"droppedEvents":{"evaluatedConfig":%d,"capturedContexts":%d}}',
            json_encode($serverSdkKey, self::JSON_FLAGS),
            json_encode($metaContext, self::JSON_FLAGS),
            implode(',', $this->capturedContextJsonsByKindAndIdentifier->entriesByKey()),
            implode(',', $this->trackedEventQueue->entryJsons()),
            implode(',', $evaluatedConfig),
            implode(',', $invalidContext),
            $this->droppedEvaluations,
            $this->droppedCapturedContexts,
        );
    }

    /**
     * @return array<array-key, int<1, max>>
     */
    private static function countsIn(mixed $countsByText): array
    {
        $counts = [];
        foreach (is_array($countsByText) ? $countsByText : [] as $text => $count) {
            if (is_int($count) && $count > 0) {
                $counts[$text] = $count;
            }
        }

        return $counts;
    }

    private static function isAJsonObject(string $text): bool
    {
        return str_starts_with($text, '{') && json_validate($text);
    }
}
