<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Telemetry;

use ConfigDirector\Internal\Identity\SdkIdentity;
use ConfigDirector\Internal\LocalStore\DroppedTelemetryCounts;
use ConfigDirector\Internal\LocalStore\StoredNumber;

final class ReportsPerMetaContext
{
    private const int META_CONTEXTS_OF_OTHER_CLIENTS_WITH_A_REPORT_OF_THEIR_OWN = 20;

    private const array FIELDS_OF_A_META_CONTEXT = [
        'sdkName' => true,
        'sdkVersion' => true,
        'appName' => true,
        'appVersion' => true,
    ];

    private const int JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE;

    private readonly CollectedTelemetry $reportOfTheSender;

    private readonly string $metaContextJsonOfTheSender;

    /** @var array<string, CollectedTelemetry> */
    private array $reportsOfOtherClientsByMetaContextJson = [];

    /**
     * @param array<string, string> $metaContextOfTheSender
     */
    public function __construct(
        array $metaContextOfTheSender,
        private readonly int $eventQueueLimit,
        private readonly int $latestCollectingSinceEpochMilliseconds,
    ) {
        $this->reportOfTheSender = new CollectedTelemetry(
            $eventQueueLimit,
            $latestCollectingSinceEpochMilliseconds,
            $metaContextOfTheSender,
        );
        $this->metaContextJsonOfTheSender = json_encode($metaContextOfTheSender, self::JSON_FLAGS);
    }

    public function addTelemetryOfTheFileLine(string $telemetryFileLine): void
    {
        $telemetryOfAClient = json_decode($telemetryFileLine, associative: true);
        if (!is_array($telemetryOfAClient)) {
            return;
        }

        $collectingSince = $telemetryOfAClient['collectingSince'] ?? null;
        if (!StoredNumber::isEpochMilliseconds($collectingSince)) {
            return;
        }

        $this->reportOfTheClientWith(self::metaContextIn($telemetryOfAClient))->addTelemetryOfAClient(
            $collectingSince,
            $telemetryOfAClient,
        );
    }

    public function countAsDroppedUnderTheSender(DroppedTelemetryCounts $droppedCounts): void
    {
        $this->reportOfTheSender->countAsDropped($droppedCounts->evaluations, $droppedCounts->capturedContexts);
    }

    public function trackedEventsLeftOut(): int
    {
        $trackedEventsLeftOut = $this->reportOfTheSender->trackedEventsLeftOut();
        foreach ($this->reportsOfOtherClientsByMetaContextJson as $report) {
            $trackedEventsLeftOut += $report->trackedEventsLeftOut();
        }

        return $trackedEventsLeftOut;
    }

    /**
     * @return list<CollectedTelemetry>
     */
    public function reportsHoldingTelemetry(): array
    {
        return array_values(array_filter(
            [$this->reportOfTheSender, ...array_values($this->reportsOfOtherClientsByMetaContextJson)],
            static fn(CollectedTelemetry $report): bool => !$report->isEmpty(),
        ));
    }

    /**
     * @param array<string, string>|null $metaContext
     */
    private function reportOfTheClientWith(?array $metaContext): CollectedTelemetry
    {
        if ($metaContext === null) {
            return $this->reportOfTheSender;
        }

        $metaContextJson = json_encode($metaContext, self::JSON_FLAGS);
        if ($metaContextJson === $this->metaContextJsonOfTheSender) {
            return $this->reportOfTheSender;
        }

        $reportOfThatMetaContext = $this->reportsOfOtherClientsByMetaContextJson[$metaContextJson] ?? null;
        if ($reportOfThatMetaContext !== null) {
            return $reportOfThatMetaContext;
        }

        $otherMetaContexts = count($this->reportsOfOtherClientsByMetaContextJson);
        if ($otherMetaContexts >= self::META_CONTEXTS_OF_OTHER_CLIENTS_WITH_A_REPORT_OF_THEIR_OWN) {
            return $this->reportOfTheSender;
        }

        return $this->reportsOfOtherClientsByMetaContextJson[$metaContextJson] = new CollectedTelemetry(
            $this->eventQueueLimit,
            $this->latestCollectingSinceEpochMilliseconds,
            $metaContext,
        );
    }

    /**
     * @param array<array-key, mixed> $telemetryOfAClient
     *
     * @return array<string, string>|null
     */
    private static function metaContextIn(array $telemetryOfAClient): ?array
    {
        $metaContext = $telemetryOfAClient['metaContext'] ?? null;
        if (!is_array($metaContext)) {
            return null;
        }

        $fieldsWritten = array_intersect_key($metaContext, self::FIELDS_OF_A_META_CONTEXT);
        $fieldsWrittenAsText = array_filter($fieldsWritten, is_string(...));
        if (
            count($fieldsWrittenAsText) !== count($fieldsWritten)
            || !isset($fieldsWrittenAsText['sdkName'], $fieldsWrittenAsText['sdkVersion'])
        ) {
            return null;
        }

        return SdkIdentity::metaContextNaming(
            $fieldsWrittenAsText['sdkName'],
            $fieldsWrittenAsText['sdkVersion'],
            $fieldsWrittenAsText['appName'] ?? null,
            $fieldsWrittenAsText['appVersion'] ?? null,
        );
    }
}
