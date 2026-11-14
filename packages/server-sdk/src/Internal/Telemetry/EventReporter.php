<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Telemetry;

use ConfigDirector\Internal\LocalStore\LocalStore;
use ConfigDirector\Internal\LocalStore\StoredTelemetry;
use ConfigDirector\Internal\LocalStore\TelemetryLock;
use ConfigDirector\Internal\LocalStore\TelemetrySendingPause;
use ConfigDirector\Internal\Time\Clock;
use Psr\Log\LoggerInterface;

final readonly class EventReporter
{
    private const int ONE_HOUR_MILLISECONDS = 3_600_000;

    public function __construct(
        private TelemetryEndpoint $endpoint,
        private int $eventQueueLimit,
        private Clock $clock,
        private LoggerInterface $logger,
    ) {}

    public function sendingIsPausedUnder(TelemetryLock $telemetryLock): bool
    {
        $now = $this->clock->epochMilliseconds();
        $sendingPause = $telemetryLock->sendingPause($this->endpoint->url);

        return $sendingPause !== null && $now < $sendingPause->epochMillisecondsSendingIsHeldUntil($now);
    }

    public function send(StoredTelemetry $storedTelemetry, LocalStore $store): void
    {
        $now = $this->clock->epochMilliseconds();
        $report = new CollectedTelemetry($this->eventQueueLimit, $now);
        foreach ($storedTelemetry->telemetryFileLines as $telemetryFileLine) {
            $report->addTelemetryOfTheFileLine($telemetryFileLine);
        }
        $report->countAsDropped($storedTelemetry->droppedEvaluations, $storedTelemetry->droppedCapturedContexts);
        if ($report->isEmpty()) {
            return;
        }
        if ($report->trackedEventsLeftOut() > 0) {
            $this->logger->warning(TrackedEventWarning::ReportAlreadyFull->messageOfTheFirstInABatch());
        }

        $answer = $this->endpoint->answerToAReportOf($report, $now);
        match ($answer->kind) {
            ReportAnswerKind::Accepted => $this->logger->debug('[EventReporter] Telemetry report successfully sent.'),
            ReportAnswerKind::RefusedUnrecoverably => $this->stopTelemetryForOneHour($answer, $store),
            ReportAnswerKind::Refused => $this->discardTheReportRefusedBy($answer, $report, $store),
            ReportAnswerKind::Unanswered => $this->discardTheReportLeftUnansweredBy($answer, $report, $store),
        };
    }

    private function discardTheReportRefusedBy(
        ReportAnswer $refusal,
        CollectedTelemetry $report,
        LocalStore $store,
    ): void {
        $this->logger->warning(
            '[EventReporter] The telemetry endpoint responded with status {status}. The events in this report were '
            . 'discarded.',
            ['status' => $refusal->status],
        );
        $this->countAsDropped($report, $store);
    }

    private function discardTheReportLeftUnansweredBy(
        ReportAnswer $answer,
        CollectedTelemetry $report,
        LocalStore $store,
    ): void {
        $this->logger->warning(
            '[EventReporter] Error attempting to send telemetry data: {error}',
            ['error' => $answer->detail],
        );
        $this->countAsDropped($report, $store);
    }

    private function stopTelemetryForOneHour(ReportAnswer $refusal, LocalStore $store): void
    {
        $this->logger->warning(
            '[EventReporter] Received a fatal status response ({status}) from the telemetry endpoint. No more '
            . 'telemetry data will be sent for one hour.',
            ['status' => $refusal->status],
        );

        $store->whileHoldingTheTelemetryLock(function (TelemetryLock $telemetryLock) use ($refusal): void {
            $now = $this->clock->epochMilliseconds();
            $telemetryLock->recordSendingPause(
                $this->endpoint->url,
                new TelemetrySendingPause($now, $now + self::ONE_HOUR_MILLISECONDS, $refusal->status),
            );
            $telemetryLock->discardStoredTelemetry();
        });
    }

    private function countAsDropped(CollectedTelemetry $unsentReport, LocalStore $store): void
    {
        $store->whileHoldingTheTelemetryLock(function (TelemetryLock $telemetryLock) use ($unsentReport): void {
            $telemetryLock->countAsDropped(
                $unsentReport->evaluationsCollected(),
                $unsentReport->capturedContextsCollected(),
                $this->clock->epochMilliseconds(),
            );
        });
    }
}
