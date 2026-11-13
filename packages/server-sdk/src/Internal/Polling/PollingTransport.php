<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Polling;

use ConfigDirector\ConfigDirectorConnectionException;
use ConfigDirector\Internal\Connection\UndeliveredPayload;
use ConfigDirector\Internal\LocalStore\LocalStore;
use ConfigDirector\Internal\LocalStore\PollingPause;
use ConfigDirector\Internal\LocalStore\RefreshLock;
use ConfigDirector\Internal\LocalStore\StoredPayload;
use ConfigDirector\Internal\LocalStore\UnfilledStore;
use ConfigDirector\Internal\Time\ClientLifetime;
use ConfigDirector\Internal\Time\Clock;
use Psr\Log\LoggerInterface;

final class PollingTransport
{
    private const int ONE_HOUR_MILLISECONDS = 3_600_000;

    private const int MILLISECONDS_BETWEEN_LOOKS_AT_THE_STORE = 1_000;

    private ?int $pollDueAtMonotonicMilliseconds = null;

    private ?int $lookedAtTheStoreAtMonotonicMilliseconds = null;

    private bool $isClosed = false;

    /**
     * @param \Closure(ConfigDirectorConnectionException): void $reportUnrecoverableError
     * @param \Closure(StoredPayload): void $reportPayloadReceivedByAPoll
     */
    public function __construct(
        private readonly LocalStore $store,
        private readonly PollingEndpoint $endpoint,
        private readonly PollingInterval $pollingInterval,
        private readonly int $timeoutMilliseconds,
        private readonly ClientLifetime $clientLifetime,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
        private readonly \Closure $reportUnrecoverableError,
        private readonly \Closure $reportPayloadReceivedByAPoll,
    ) {}

    public function storedPayloadForInitialization(): StoredPayload|UndeliveredPayload
    {
        $now = $this->clock->epochMilliseconds();
        $storedPayload = $this->store->storedPayload();
        $pollingPause = $storedPayload === null ? $this->store->pollingPause($this->endpoint->url) : null;
        $isPausedAfterAFailedPoll = $pollingPause !== null
            && $pollingPause->pollFailed
            && $now < $pollingPause->epochMillisecondsPollsAreHeldUntil($now);
        if ($storedPayload !== null || $isPausedAfterAFailedPoll) {
            $this->expectTheNextPollAt($this->epochMillisecondsThePollIsDue($storedPayload, $now));

            return $storedPayload ?? UndeliveredPayload::FailedPollOnRecord;
        }

        $pollAttempt = null;
        $filledStore = $this->store->storedPayloadFilledWithin(
            $this->timeoutMilliseconds,
            function (int $remainingMilliseconds, RefreshLock $refreshLock) use (&$pollAttempt): ?StoredPayload {
                $pollAttempt = $this->pollAttemptedUnder($refreshLock, null, $remainingMilliseconds);

                return $pollAttempt->freshPayload;
            },
        );
        if ($pollAttempt instanceof PollAttempt) {
            $this->expectTheNextPollAt($pollAttempt->nextPollDueAtEpochMilliseconds);
            $this->logAndReportTheFailureOf($pollAttempt);
            $this->reportThePayloadTheServerSent(
                $pollAttempt,
                $filledStore instanceof StoredPayload ? $filledStore : null,
            );
        } else {
            $this->expectTheNextPollAt($this->epochMillisecondsThePollIsDue(
                $filledStore instanceof StoredPayload ? $filledStore : null,
                $this->clock->epochMilliseconds(),
            ));
        }
        if ($filledStore instanceof StoredPayload) {
            return $filledStore;
        }

        $pollWasUnsentBecause = $pollAttempt instanceof PollAttempt ? $pollAttempt->unsentBecause : null;

        return match ($filledStore) {
            UnfilledStore::RefreshLockHeldPastTheTimeout => UndeliveredPayload::WaitReachedTheTimeout,
            UnfilledStore::UnusableAsItsErrorLineSays => UndeliveredPayload::ReasonLoggedAsAnError,
            UnfilledStore::NothingFetched => match ($pollWasUnsentBecause) {
                UnsentPoll::NoTimeLeft => UndeliveredPayload::WaitReachedTheTimeout,
                UnsentPoll::NotDue => UndeliveredPayload::FailedPollOnRecord,
                UnsentPoll::PollingPauseNotRecorded => UndeliveredPayload::ReasonLoggedAsAnError,
                null => UndeliveredPayload::ReasonLoggedAsAnError,
            },
        };
    }

    public function storedPayloadFoundByAReadInALongRunningProcess(): ?StoredPayload
    {
        $nowMonotonicMilliseconds = $this->clock->monotonicMilliseconds();
        $hasLookedAtTheStoreRecently = $this->lookedAtTheStoreAtMonotonicMilliseconds !== null
            && $nowMonotonicMilliseconds - $this->lookedAtTheStoreAtMonotonicMilliseconds
                < self::MILLISECONDS_BETWEEN_LOOKS_AT_THE_STORE;
        $isInALongRunningProcess = $this->clientLifetime->showsALongRunningProcess();
        if ($this->isClosed || !$isInALongRunningProcess || $hasLookedAtTheStoreRecently) {
            return null;
        }

        $this->lookedAtTheStoreAtMonotonicMilliseconds = $nowMonotonicMilliseconds;
        $storedPayload = $this->store->storedPayload();
        $now = $this->clock->epochMilliseconds();
        $freshUntil = $storedPayload?->epochMillisecondsFreshUntil($this->pollingInterval->milliseconds(), $now) ?? 0;
        if ($now < $freshUntil) {
            $this->expectTheNextPollAt($freshUntil);
        }

        return $this->payloadStoredByThePollThatIsDue() ?? $storedPayload;
    }

    public function close(): void
    {
        $this->payloadStoredByThePollThatIsDue();
        $this->isClosed = true;
    }

    private function payloadStoredByThePollThatIsDue(): ?StoredPayload
    {
        $isDue = $this->pollDueAtMonotonicMilliseconds !== null
            && $this->clock->monotonicMilliseconds() >= $this->pollDueAtMonotonicMilliseconds;
        if ($this->isClosed || !$isDue) {
            return null;
        }

        $refreshLock = $this->store->refreshLockAcquiredWithin(0);
        if ($refreshLock === null) {
            $this->expectTheNextPollAt($this->clock->epochMilliseconds() + $this->pollingInterval->milliseconds());

            return null;
        }

        try {
            $pollAttempt = $this->pollAttemptedUnder(
                $refreshLock,
                $this->store->storedPayload(),
                $this->timeoutMilliseconds,
            );
            $payloadStored = $pollAttempt->freshPayload !== null
                && $refreshLock->replaceStoredPayload($pollAttempt->freshPayload)
                    ? $pollAttempt->freshPayload
                    : null;
        } finally {
            $refreshLock->release();
        }

        $this->expectTheNextPollAt($pollAttempt->nextPollDueAtEpochMilliseconds);
        $this->logAndReportTheFailureOf($pollAttempt);
        $this->reportThePayloadTheServerSent($pollAttempt, $payloadStored);

        return $payloadStored;
    }

    private function reportThePayloadTheServerSent(PollAttempt $pollAttempt, ?StoredPayload $payloadStored): void
    {
        if ($payloadStored !== null && $pollAttempt->freshPayloadCameFromTheServer) {
            ($this->reportPayloadReceivedByAPoll)($payloadStored);
        }
    }

    private function expectTheNextPollAt(int $epochMilliseconds): void
    {
        $this->pollDueAtMonotonicMilliseconds = $this->clock->monotonicMilliseconds()
            + $epochMilliseconds
            - $this->clock->epochMilliseconds();
    }

    private function epochMillisecondsThePollIsDue(?StoredPayload $storedPayload, int $now): int
    {
        $freshUntil = $storedPayload?->epochMillisecondsFreshUntil($this->pollingInterval->milliseconds(), $now) ?? 0;
        if ($now < $freshUntil) {
            return $freshUntil;
        }

        return $this->store->pollingPause($this->endpoint->url)?->epochMillisecondsPollsAreHeldUntil($now) ?? 0;
    }

    private function pollAttemptedUnder(
        RefreshLock $refreshLock,
        ?StoredPayload $storedPayload,
        int $timeLimitMilliseconds,
    ): PollAttempt {
        $now = $this->clock->epochMilliseconds();
        $pollDueAt = $this->epochMillisecondsThePollIsDue($storedPayload, $now);
        if ($timeLimitMilliseconds < 1) {
            return PollAttempt::unsent(UnsentPoll::NoTimeLeft, $pollDueAt);
        }
        if ($now < $pollDueAt) {
            return PollAttempt::unsent(UnsentPoll::NotDue, $pollDueAt);
        }

        $pollingIntervalMilliseconds = $this->pollingInterval->milliseconds();
        $sessionId = $storedPayload->sessionId
            ?? $this->store->pollingPause($this->endpoint->url)->sessionId
            ?? self::newSessionId();
        $pauseWhileThePollIsUnanswered = new PollingPause(
            $sessionId,
            $now,
            $now + $pollingIntervalMilliseconds,
            false,
            null,
        );
        if (!$refreshLock->recordPollingPause($this->endpoint->url, $pauseWhileThePollIsUnanswered)) {
            return PollAttempt::unsent(
                UnsentPoll::PollingPauseNotRecorded,
                $pauseWhileThePollIsUnanswered->untilEpochMilliseconds,
            );
        }

        $timestampOfTheStoredPayload = $storedPayload->payload['timestamp'] ?? null;
        $answer = $this->answerRetriedOnceWhenUnanswered(
            $sessionId,
            is_string($timestampOfTheStoredPayload) ? $timestampOfTheStoredPayload : null,
            $timeLimitMilliseconds,
        );
        $answeredAt = $this->clock->epochMilliseconds();
        $pauseAfterTheFailedPollOf = static fn(int $pauseMilliseconds): PollingPause => new PollingPause(
            $sessionId,
            $answeredAt,
            $answeredAt + $pauseMilliseconds,
            true,
            $answer->status,
        );

        return match ($answer->kind) {
            PollAnswerKind::Payload => PollAttempt::answeredWithAPayload(
                new StoredPayload(
                    self::payloadAfterReceiving($answer->payload, $storedPayload),
                    $answeredAt,
                    $sessionId,
                ),
                $answeredAt + $pollingIntervalMilliseconds,
            ),
            PollAnswerKind::Unchanged => $storedPayload === null
                ? $this->failedPollAttempt(
                    $refreshLock,
                    $pauseAfterTheFailedPollOf($pollingIntervalMilliseconds),
                    self::failureWithStatus(204),
                )
                : PollAttempt::answeredThatNothingChanged(
                    new StoredPayload($storedPayload->payload, $answeredAt, $sessionId),
                    $answeredAt + $pollingIntervalMilliseconds,
                ),
            PollAnswerKind::Throttled => $this->failedPollAttempt(
                $refreshLock,
                $pauseAfterTheFailedPollOf(max(
                    $pollingIntervalMilliseconds,
                    min(self::retryAfterMillisecondsOf($answer, $answeredAt), self::ONE_HOUR_MILLISECONDS),
                )),
                self::failureWithStatus($answer->status),
            ),
            PollAnswerKind::RefusedUnrecoverably => $this->failedPollAttempt(
                $refreshLock,
                $pauseAfterTheFailedPollOf(max($pollingIntervalMilliseconds, self::ONE_HOUR_MILLISECONDS)),
                new ConfigDirectorConnectionException(
                    sprintf(
                        'Connection failed with status: %d%s. This is an unrecoverable error, the SDK will not '
                        . 'connect again for one hour.',
                        $answer->status,
                        $answer->detail === '' ? '' : " ({$answer->detail})",
                    ),
                    $answer->status,
                ),
                failedUnrecoverably: true,
            ),
            PollAnswerKind::Refused => $this->failedPollAttempt(
                $refreshLock,
                $pauseAfterTheFailedPollOf($pollingIntervalMilliseconds),
                self::failureWithStatus($answer->status),
            ),
            PollAnswerKind::Unanswered, PollAnswerKind::TimedOut => $this->failedPollAttempt(
                $refreshLock,
                $pauseAfterTheFailedPollOf($pollingIntervalMilliseconds),
                new ConfigDirectorConnectionException("Connection failed with error: {$answer->detail}."),
            ),
            PollAnswerKind::Unreadable => $this->failedPollAttempt(
                $refreshLock,
                $pauseAfterTheFailedPollOf($pollingIntervalMilliseconds),
                new ConfigDirectorConnectionException(
                    "Failed to parse the response from the server: {$answer->detail}",
                ),
            ),
        };
    }

    private function answerRetriedOnceWhenUnanswered(
        string $sessionId,
        ?string $lastUpdateTimestamp,
        int $timeLimitMilliseconds,
    ): PollAnswer {
        $startedAt = hrtime(true);
        $answer = $this->endpoint->answerToAPoll($sessionId, $lastUpdateTimestamp, $timeLimitMilliseconds);
        $remainingMilliseconds = $timeLimitMilliseconds - intdiv(hrtime(true) - $startedAt, 1_000_000);
        if ($answer->kind !== PollAnswerKind::Unanswered || $remainingMilliseconds < 1) {
            return $answer;
        }

        $this->logger->debug(
            '[PollingTransport] The poll got no answer and is sent again at once',
            ['cause' => $answer->detail],
        );

        return $this->endpoint->answerToAPoll($sessionId, $lastUpdateTimestamp, $remainingMilliseconds);
    }

    private function failedPollAttempt(
        RefreshLock $refreshLock,
        PollingPause $pollingPause,
        ConfigDirectorConnectionException $failure,
        bool $failedUnrecoverably = false,
    ): PollAttempt {
        $refreshLock->recordPollingPause($this->endpoint->url, $pollingPause);

        return PollAttempt::failed($failure, $failedUnrecoverably, $pollingPause->untilEpochMilliseconds);
    }

    private function logAndReportTheFailureOf(PollAttempt $pollAttempt): void
    {
        if ($pollAttempt->failure === null) {
            return;
        }
        if (!$pollAttempt->failedUnrecoverably) {
            $this->logger->error('[PollingTransport] Error during polling:', ['exception' => $pollAttempt->failure]);

            return;
        }

        $this->logger->error(
            '[PollingTransport] The connection encountered an unrecoverable error and will not be retried for one '
            . 'hour. Configs will not receive updates until then.',
            ['exception' => $pollAttempt->failure],
        );
        ($this->reportUnrecoverableError)($pollAttempt->failure);
    }

    private static function failureWithStatus(?int $status): ConfigDirectorConnectionException
    {
        return new ConfigDirectorConnectionException("Connection failed with status: {$status}", $status);
    }

    private static function retryAfterMillisecondsOf(PollAnswer $answer, int $nowEpochMilliseconds): int
    {
        $retryAfterSeconds = $answer->retryAfterHeader === null
            ? null
            : RetryAfterHeader::secondsNamedBy($answer->retryAfterHeader, $nowEpochMilliseconds);

        return ($retryAfterSeconds ?? 0) * 1_000;
    }

    /**
     * @param array<array-key, mixed> $receivedPayload
     *
     * @return array<array-key, mixed>
     */
    private static function payloadAfterReceiving(array $receivedPayload, ?StoredPayload $storedPayload): array
    {
        if ($storedPayload === null || ($receivedPayload['kind'] ?? null) !== 'delta') {
            return $receivedPayload;
        }

        $mergedPayload = array_replace($storedPayload->payload, $receivedPayload);
        $mergedPayload['kind'] = 'full';
        foreach (['configs', 'segments'] as $payloadSection) {
            $storedSection = $storedPayload->payload[$payloadSection] ?? null;
            $receivedSection = $receivedPayload[$payloadSection] ?? null;
            $mergedPayload[$payloadSection] = array_replace(
                is_array($storedSection) ? $storedSection : [],
                is_array($receivedSection) ? $receivedSection : [],
            );
        }

        return $mergedPayload;
    }

    private static function newSessionId(): string
    {
        $randomBytes = random_bytes(16);
        $randomBytes[6] = chr(ord($randomBytes[6]) & 0x0F | 0x40);
        $randomBytes[8] = chr(ord($randomBytes[8]) & 0x3F | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($randomBytes), 4));
    }
}
