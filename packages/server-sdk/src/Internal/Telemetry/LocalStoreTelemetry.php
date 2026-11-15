<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Telemetry;

use ConfigDirector\ConfigEvaluation;
use ConfigDirector\ConfigState;
use ConfigDirector\Internal\LocalStore\ExclusiveTelemetryLock;
use ConfigDirector\Internal\LocalStore\LocalStore;
use ConfigDirector\Internal\LocalStore\LocalStoreOpenedOnFirstUse;
use ConfigDirector\Internal\LocalStore\SharedTelemetryLock;
use ConfigDirector\Internal\LocalStore\StoredTelemetry;
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
        $this->telemetryOfThisClient = new CollectedTelemetry(
            $eventQueueLimit,
            collectingSinceEpochMilliseconds: $clock->epochMilliseconds(),
        );
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
            $this->logger->warning($warning->messageOfTheFirstInABatch());
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
            collectingSinceEpochMilliseconds: $this->clock->epochMilliseconds(),
        );
        if ($telemetryToMove->isEmpty() && !$this->localStore->isOpened()) {
            return;
        }

        $store = $this->localStore->store();
        $workLeftAfterAdding = $store->whileHoldingTheTelemetryLockShared(
            fn(SharedTelemetryLock $telemetryLock): WorkUnderTheExclusiveTelemetryLock
                => $this->workLeftAfterAdding($telemetryToMove, $telemetryLock, $sendsWhatTheStoreHoldsAtOnce, $store),
        );
        $telemetryToSend = match ($workLeftAfterAdding) {
            null, WorkUnderTheExclusiveTelemetryLock::Nothing => null,
            WorkUnderTheExclusiveTelemetryLock::StartingTheTally,
            WorkUnderTheExclusiveTelemetryLock::TakingTheStoredTelemetry => $this->telemetryTakenUnderTheExclusiveLock(
                $telemetryToMove,
                $sendsWhatTheStoreHoldsAtOnce,
                $store,
            ),
        };
        if ($telemetryToSend !== null) {
            $this->eventReporter->send($telemetryToSend, $store);
        }
    }

    private function telemetryTakenUnderTheExclusiveLock(
        CollectedTelemetry $telemetryMoved,
        bool $sendsWhatTheStoreHoldsAtOnce,
        LocalStore $store,
    ): ?StoredTelemetry {
        $workDecidedAgainUnder = fn(ExclusiveTelemetryLock $telemetryLock): ?StoredTelemetry
            => $this->workDecidedAgainUnder($telemetryLock, $telemetryMoved, $sendsWhatTheStoreHoldsAtOnce, $store);

        return $sendsWhatTheStoreHoldsAtOnce
            ? $store->whileHoldingTheTelemetryLockExclusive($workDecidedAgainUnder)
            : $store->whileHoldingTheTelemetryLockExclusiveIfItIsFree($workDecidedAgainUnder);
    }

    private function workLeftAfterAdding(
        CollectedTelemetry $telemetryToMove,
        SharedTelemetryLock $telemetryLock,
        bool $sendsWhatTheStoreHoldsAtOnce,
        LocalStore $store,
    ): WorkUnderTheExclusiveTelemetryLock {
        if ($this->eventReporter->sendingIsPausedUnder($telemetryLock)) {
            return WorkUnderTheExclusiveTelemetryLock::Nothing;
        }

        $isKept = $telemetryToMove->isEmpty()
            || $telemetryLock->addTelemetryOfAClient($telemetryToMove->telemetryFileLine());
        if (!$isKept) {
            $telemetryLock->countAsDropped(
                $telemetryToMove->evaluationsCollected(),
                $telemetryToMove->capturedContextsCollected(),
            );
        }

        return $this->workUnderTheExclusiveTelemetryLock(
            $telemetryLock->collectingSinceEpochMilliseconds(),
            $telemetryToMove,
            $sendsWhatTheStoreHoldsAtOnce,
            $store,
        );
    }

    private function workDecidedAgainUnder(
        ExclusiveTelemetryLock $telemetryLock,
        CollectedTelemetry $telemetryMoved,
        bool $sendsWhatTheStoreHoldsAtOnce,
        LocalStore $store,
    ): ?StoredTelemetry {
        if ($this->eventReporter->sendingIsPausedUnder($telemetryLock)) {
            return null;
        }

        $workNowThatTheLockIsHeld = $this->workUnderTheExclusiveTelemetryLock(
            $telemetryLock->collectingSinceEpochMilliseconds(),
            $telemetryMoved,
            $sendsWhatTheStoreHoldsAtOnce,
            $store,
        );

        return match ($workNowThatTheLockIsHeld) {
            WorkUnderTheExclusiveTelemetryLock::Nothing => null,
            WorkUnderTheExclusiveTelemetryLock::StartingTheTally => $telemetryLock->startCollectingAt(
                $telemetryMoved->collectingSinceEpochMilliseconds(),
            ),
            WorkUnderTheExclusiveTelemetryLock::TakingTheStoredTelemetry => $telemetryLock->takeStoredTelemetry(
                $this->clock->epochMilliseconds(),
            ),
        };
    }

    private function workUnderTheExclusiveTelemetryLock(
        ?int $talliedCollectingSince,
        CollectedTelemetry $telemetryMoved,
        bool $sendsWhatTheStoreHoldsAtOnce,
        LocalStore $store,
    ): WorkUnderTheExclusiveTelemetryLock {
        $collectingSinceOfThisClient = $telemetryMoved->isEmpty()
            ? null
            : $telemetryMoved->collectingSinceEpochMilliseconds();
        $storeCollectingSince = $talliedCollectingSince ?? $collectingSinceOfThisClient;
        if ($storeCollectingSince === null) {
            return WorkUnderTheExclusiveTelemetryLock::Nothing;
        }

        $now = $this->clock->epochMilliseconds();
        $isDue = $sendsWhatTheStoreHoldsAtOnce
            || $now - $storeCollectingSince >= $this->flushIntervalMilliseconds
            || $storeCollectingSince > $now;
        if ($isDue) {
            return $store->isHeldByAStreamingProcess()
                ? WorkUnderTheExclusiveTelemetryLock::Nothing
                : WorkUnderTheExclusiveTelemetryLock::TakingTheStoredTelemetry;
        }

        return $talliedCollectingSince === null
            ? WorkUnderTheExclusiveTelemetryLock::StartingTheTally
            : WorkUnderTheExclusiveTelemetryLock::Nothing;
    }
}
