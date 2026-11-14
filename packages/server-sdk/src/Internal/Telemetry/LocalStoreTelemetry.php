<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Telemetry;

use ConfigDirector\ConfigEvaluation;
use ConfigDirector\ConfigState;
use ConfigDirector\Internal\LocalStore\LocalStore;
use ConfigDirector\Internal\LocalStore\LocalStoreOpenedOnFirstUse;
use ConfigDirector\Internal\LocalStore\StoredTelemetry;
use ConfigDirector\Internal\LocalStore\TelemetryLock;
use ConfigDirector\Internal\Time\ClientLifetime;
use ConfigDirector\Internal\Time\Clock;
use ConfigDirector\Internal\Value\InCodeDefaultValue;
use Psr\Log\LoggerInterface;

final class LocalStoreTelemetry implements ClientTelemetry
{
    private CollectedTelemetry $telemetryOfThisClient;

    private int $movedToTheStoreAtMonotonicMilliseconds;

    private bool $isClosed = false;

    public function __construct(
        private readonly LocalStoreOpenedOnFirstUse $localStore,
        private readonly EventReporter $eventReporter,
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
    public function recordTrackedEvent(TrackCall $call, \Closure $admittedContextOf): void
    {
        $warnings = $this->telemetryOfThisClient->keepTrackedEvent(
            TrackedEventAdmission::of($call, $admittedContextOf($call->context)),
            $this->clock->epochMilliseconds(),
        );
        foreach ($warnings as $warning) {
            $this->warnAboutTrackedEvents($warning);
        }
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
                $store,
            ),
        );
        if ($telemetryToSend !== null) {
            $this->eventReporter->send($telemetryToSend, $store);
        }
    }

    private function telemetryToSendAfterAdding(
        CollectedTelemetry $telemetryOfThisClient,
        TelemetryLock $telemetryLock,
        bool $sendsWhatTheStoreHoldsAtOnce,
        LocalStore $store,
    ): ?StoredTelemetry {
        if ($this->eventReporter->sendingIsPausedUnder($telemetryLock)) {
            return null;
        }

        $now = $this->clock->epochMilliseconds();

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

        $isSentByThisProcess = $isDue && !$store->isHeldByAStreamingProcess();

        return $isSentByThisProcess ? $telemetryLock->takeStoredTelemetry($now) : null;
    }

    private function warnAboutTrackedEvents(TrackedEventWarning $warning): void
    {
        $this->logger->warning($warning->messageOfTheFirstInABatch());
    }
}
