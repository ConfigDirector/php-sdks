<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Telemetry;

use ConfigDirector\ConfigEvaluation;
use ConfigDirector\ConfigState;
use ConfigDirector\Internal\LocalStore\LocalStore;
use ConfigDirector\Internal\LocalStore\LocalStoreOpenedOnFirstUse;
use ConfigDirector\Internal\LocalStore\StoredTelemetry;
use ConfigDirector\Internal\LocalStore\TelemetryLock;
use ConfigDirector\Internal\LocalStore\TelemetrySendingPause;
use ConfigDirector\Internal\Time\ClientLifetime;
use ConfigDirector\Internal\Time\Clock;
use ConfigDirector\Internal\Value\InCodeDefaultValue;
use Psr\Log\LoggerInterface;

final class LocalStoreTelemetry implements ClientTelemetry
{
    private const int ONE_HOUR_MILLISECONDS = 3_600_000;

    private CollectedTelemetry $telemetryOfThisClient;

    private int $movedToTheStoreAtMonotonicMilliseconds;

    private bool $isClosed = false;

    public function __construct(
        private readonly LocalStoreOpenedOnFirstUse $localStore,
        private readonly TelemetryEndpoint $endpoint,
        private readonly int $eventQueueLimit,
        private readonly int $flushIntervalMilliseconds,
        private readonly ClientLifetime $clientLifetime,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
    ) {
        $this->telemetryOfThisClient = new CollectedTelemetry($eventQueueLimit, $clock->epochMilliseconds());
        $this->movedToTheStoreAtMonotonicMilliseconds = $clock->monotonicMilliseconds();
    }

    #[\Override]
    public function recordEvaluation(
        ConfigEvaluation $evaluation,
        InCodeDefaultValue $inCodeDefaultValue,
        ?ConfigState $stateServed,
    ): void {
        if ($this->isClosed) {
            return;
        }

        $reportedContext = ReportedContext::of($evaluation->context);
        try {
            $capturedContextJson = $reportedContext?->capturedContextJson();
            $eventJson = ReportedEvaluation::eventJson(
                $evaluation,
                $inCodeDefaultValue,
                $stateServed,
                $reportedContext,
            );
        } catch (\Throwable $exception) {
            $this->logger->debug(
                "[TelemetryEventCollector] The evaluation of '{configKey}' is not reported because it could not be "
                . 'written as JSON.',
                ['configKey' => $evaluation->key, 'exception' => $exception],
            );

            return;
        }

        if ($reportedContext !== null && $capturedContextJson !== null) {
            $this->telemetryOfThisClient->captureContext($reportedContext->kindAndIdentifier(), $capturedContextJson);
        }
        $this->telemetryOfThisClient->countEvaluations($eventJson, 1);
    }

    #[\Override]
    public function recordInvalidContextKind(string $kindSample): InvalidContextKindRecording
    {
        return $this->telemetryOfThisClient->countInvalidContexts($kindSample, 1);
    }

    #[\Override]
    public function storeAndSendWhatIsDueInALongRunningProcess(): void
    {
        $nowMonotonicMilliseconds = $this->clock->monotonicMilliseconds();
        $millisecondsSinceItMovedToTheStore = $nowMonotonicMilliseconds - $this->movedToTheStoreAtMonotonicMilliseconds;
        if ($this->isClosed || $millisecondsSinceItMovedToTheStore <= $this->flushIntervalMilliseconds) {
            return;
        }

        $this->movedToTheStoreAtMonotonicMilliseconds = $nowMonotonicMilliseconds;
        $this->moveToTheStoreAndSend(sendsWhatTheStoreHoldsAtOnce: false);
    }

    #[\Override]
    public function close(): void
    {
        if ($this->isClosed) {
            return;
        }

        $this->isClosed = true;
        $this->moveToTheStoreAndSend(
            sendsWhatTheStoreHoldsAtOnce: $this->clientLifetime->showsALongRunningProcess(),
        );
    }

    private function moveToTheStoreAndSend(bool $sendsWhatTheStoreHoldsAtOnce): void
    {
        $telemetryToMove = $this->telemetryOfThisClient;
        $this->telemetryOfThisClient = new CollectedTelemetry(
            $this->eventQueueLimit,
            $this->clock->epochMilliseconds(),
        );
        if ($telemetryToMove->isEmpty() && !$this->localStore->isOpened()) {
            return;
        }

        $store = $this->localStore->store();
        $telemetryToSend = $store->whileHoldingTheTelemetryLock(
            fn(TelemetryLock $telemetryLock): ?StoredTelemetry => $this->telemetryToSendAfterAdding(
                $telemetryToMove,
                $telemetryLock,
                $sendsWhatTheStoreHoldsAtOnce,
            ),
        );
        if ($telemetryToSend !== null) {
            $this->send($telemetryToSend, $store);
        }
    }

    private function telemetryToSendAfterAdding(
        CollectedTelemetry $telemetryOfThisClient,
        TelemetryLock $telemetryLock,
        bool $sendsWhatTheStoreHoldsAtOnce,
    ): ?StoredTelemetry {
        $now = $this->clock->epochMilliseconds();
        $sendingPause = $telemetryLock->sendingPause($this->endpoint->url);
        if ($sendingPause !== null && $now < $sendingPause->epochMillisecondsSendingIsHeldUntil($now)) {
            return null;
        }

        $isKept = $telemetryOfThisClient->isEmpty() || $telemetryLock->addTelemetryOfAClient(
            $telemetryOfThisClient->telemetryFileLine(),
            $telemetryOfThisClient->collectingSinceEpochMilliseconds(),
        );
        if (!$isKept) {
            $telemetryLock->countAsDropped(
                $telemetryOfThisClient->evaluationsCollected(),
                $telemetryOfThisClient->capturedContextsCollected(),
                $now,
            );
        }

        $storeCollectingSince = $telemetryLock->collectingSinceEpochMilliseconds();
        $isDue = $storeCollectingSince !== null && (
            $sendsWhatTheStoreHoldsAtOnce
            || $now - $storeCollectingSince >= $this->flushIntervalMilliseconds
            || $storeCollectingSince > $now
        );

        return $isDue ? $telemetryLock->takeStoredTelemetry($now) : null;
    }

    private function send(StoredTelemetry $storedTelemetry, LocalStore $store): void
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
