<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Telemetry;

use ConfigDirector\Internal\LocalStore\ExclusiveTelemetryLock;
use ConfigDirector\Internal\LocalStore\LocalStore;
use ConfigDirector\Internal\LocalStore\SharedTelemetryLock;
use ConfigDirector\Internal\LocalStore\StoredTelemetry;
use ConfigDirector\Internal\LocalStore\TelemetrySendingPause;
use ConfigDirector\Internal\Time\Clock;
use Psr\Log\LoggerInterface;

final readonly class EventReporter
{
    private const int ONE_HOUR_MILLISECONDS = 3_600_000;

    private const int TIME_LIMIT_OF_THE_REPORTS_OF_ONE_SEND_MILLISECONDS = 5_000;

    public function __construct(
        private TelemetryEndpoint $endpoint,
        private int $eventQueueLimit,
        private Clock $clock,
        private LoggerInterface $logger,
    ) {}

    public function emptyTelemetryCollectingSince(int $epochMilliseconds): CollectedTelemetry
    {
        return new CollectedTelemetry($this->eventQueueLimit, $epochMilliseconds, $this->endpoint->metaContext);
    }

    public function sendingIsPausedUnder(SharedTelemetryLock|ExclusiveTelemetryLock $telemetryLock): bool
    {
        $now = $this->clock->epochMilliseconds();
        $sendingPause = $telemetryLock->sendingPause($this->endpoint->url);

        return $sendingPause !== null && $now < $sendingPause->epochMillisecondsSendingIsHeldUntil($now);
    }

    public function send(StoredTelemetry $storedTelemetry, LocalStore $store): void
    {
        $now = $this->clock->epochMilliseconds();
        $reports = new ReportsPerMetaContext($this->endpoint->metaContext, $this->eventQueueLimit, $now);
        foreach ($storedTelemetry->telemetryFileLinesReadOnce() as $telemetryFileLine) {
            $reports->addTelemetryOfTheFileLine($telemetryFileLine);
        }
        $reports->countAsDroppedUnderTheSender($storedTelemetry->droppedCountsReadOnce());

        if ($reports->trackedEventsLeftOut() > 0) {
            $this->logger->warning(TrackedEventWarning::ReportAlreadyFull->messageOfTheFirstInABatch());
        }

        $reportsNotYetAnswered = $reports->reportsHoldingTelemetry();
        $timeLimitEndsAtMonotonicMilliseconds = $this->clock->monotonicMilliseconds()
            + self::TIME_LIMIT_OF_THE_REPORTS_OF_ONE_SEND_MILLISECONDS;
        while ($reportsNotYetAnswered !== []) {
            $millisecondsLeft = $timeLimitEndsAtMonotonicMilliseconds - $this->clock->monotonicMilliseconds();
            $answer = $this->endpoint->answerToAReportOf($reportsNotYetAnswered[0], $now, max(1, $millisecondsLeft));
            $reportsNotYetAnswered = match ($answer->kind) {
                ReportAnswerKind::Accepted => $this->reportsLeftAfterTheFirstWasAccepted($reportsNotYetAnswered),
                ReportAnswerKind::Refused => $this->reportsLeftAfterDiscardingTheFirstRefusedBy(
                    $answer,
                    $reportsNotYetAnswered,
                    $store,
                ),
                ReportAnswerKind::RefusedUnrecoverably => $this->noReportsLeftAfterStoppingTelemetryForOneHour(
                    $answer,
                    $store,
                ),
                ReportAnswerKind::Unanswered => $this->noReportsLeftAfterDiscardingThoseLeftUnansweredBy(
                    $answer,
                    $reportsNotYetAnswered,
                    $store,
                ),
            };
        }
    }

    /**
     * @param non-empty-list<CollectedTelemetry> $reports
     *
     * @return list<CollectedTelemetry>
     */
    private function reportsLeftAfterTheFirstWasAccepted(array $reports): array
    {
        $this->logger->debug('[EventReporter] Telemetry report successfully sent.');

        return array_slice($reports, 1);
    }

    /**
     * @param non-empty-list<CollectedTelemetry> $reports
     *
     * @return list<CollectedTelemetry>
     */
    private function reportsLeftAfterDiscardingTheFirstRefusedBy(
        ReportAnswer $refusal,
        array $reports,
        LocalStore $store,
    ): array {
        $this->logger->warning(
            '[EventReporter] The telemetry endpoint responded with status {status}. The events in this report were '
            . 'discarded.',
            ['status' => $refusal->status],
        );
        $this->countAsDropped([$reports[0]], $store);

        return array_slice($reports, 1);
    }

    /**
     * @param non-empty-list<CollectedTelemetry> $reports
     *
     * @return array{}
     */
    private function noReportsLeftAfterDiscardingThoseLeftUnansweredBy(
        ReportAnswer $answer,
        array $reports,
        LocalStore $store,
    ): array {
        $this->logger->warning(
            '[EventReporter] Error attempting to send telemetry data: {error}',
            ['error' => $answer->detail],
        );
        $this->countAsDropped($reports, $store);

        return [];
    }

    /**
     * @return array{}
     */
    private function noReportsLeftAfterStoppingTelemetryForOneHour(ReportAnswer $refusal, LocalStore $store): array
    {
        $this->logger->warning(
            '[EventReporter] Received a fatal status response ({status}) from the telemetry endpoint. No more '
            . 'telemetry data will be sent for one hour.',
            ['status' => $refusal->status],
        );

        $store->whileHoldingTheTelemetryLockExclusive(function (ExclusiveTelemetryLock $telemetryLock) use (
            $refusal,
        ): void {
            $now = $this->clock->epochMilliseconds();
            $telemetryLock->recordSendingPause(
                $this->endpoint->url,
                new TelemetrySendingPause(
                    recordedAtEpochMilliseconds: $now,
                    untilEpochMilliseconds: $now + self::ONE_HOUR_MILLISECONDS,
                    refusalStatus: $refusal->status,
                ),
            );
            $telemetryLock->discardStoredTelemetry();
        });

        return [];
    }

    /**
     * @param list<CollectedTelemetry> $unsentReports
     */
    private function countAsDropped(array $unsentReports, LocalStore $store): void
    {
        $evaluations = 0;
        $capturedContexts = 0;
        foreach ($unsentReports as $unsentReport) {
            $evaluations += $unsentReport->evaluationsCollected();
            $capturedContexts += $unsentReport->capturedContextsCollected();
        }

        $store->whileHoldingTheTelemetryLockShared(
            static function (SharedTelemetryLock $telemetryLock) use ($evaluations, $capturedContexts): void {
                $telemetryLock->countAsDropped($evaluations, $capturedContexts);
            },
        );
    }
}
