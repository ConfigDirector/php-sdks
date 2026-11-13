<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Polling;

use ConfigDirector\ConfigDirectorConnectionException;
use ConfigDirector\Internal\LocalStore\LocalStore;
use ConfigDirector\Internal\LocalStore\PollingPause;
use ConfigDirector\Internal\LocalStore\RefreshLock;
use ConfigDirector\Internal\LocalStore\StoredPayload;
use ConfigDirector\Internal\Time\Clock;
use Psr\Log\LoggerInterface;

final class PollingTransport
{
    private const int ONE_HOUR_MILLISECONDS = 3_600_000;

    private readonly int $createdAtMonotonicMilliseconds;

    private ?int $pollDueAtMonotonicMilliseconds = null;

    private bool $isClosed = false;

    /**
     * @param \Closure(ConfigDirectorConnectionException): void $reportUnrecoverableError
     */
    public function __construct(
        private readonly LocalStore $store,
        private readonly PollingEndpoint $endpoint,
        private readonly PollingInterval $pollingInterval,
        private readonly int $timeoutMilliseconds,
        private readonly int $flushIntervalMilliseconds,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
        private readonly \Closure $reportUnrecoverableError,
    ) {
        $this->createdAtMonotonicMilliseconds = $clock->monotonicMilliseconds();
    }

    public function storedPayloadForInitialization(): ?StoredPayload
    {
        $now = $this->clock->epochMilliseconds();
        $storedPayload = $this->store->storedPayload();
        $pollingPause = $storedPayload === null ? $this->store->pollingPause($this->endpoint->url) : null;
        $isPausedAfterAFailedPoll = $pollingPause !== null
            && $pollingPause->pollFailed
            && $now < $pollingPause->epochMillisecondsPollsAreHeldUntil($now);
        if ($storedPayload !== null || $isPausedAfterAFailedPoll) {
            $this->expectTheNextPollAt($this->epochMillisecondsThePollIsDue($storedPayload, $now));

            return $storedPayload;
        }

        $pollAttempt = null;
        $filledPayload = $this->store->storedPayloadFilledWithin(
            $this->timeoutMilliseconds,
            function (int $remainingMilliseconds, RefreshLock $refreshLock) use (&$pollAttempt): ?StoredPayload {
                $pollAttempt = $this->pollAttemptedUnder($refreshLock, null, $remainingMilliseconds);

                return $pollAttempt->freshPayload;
            },
        );
        if ($pollAttempt instanceof PollAttempt) {
            $this->expectTheNextPollAt($pollAttempt->nextPollDueAtEpochMilliseconds);
            $this->logAndReportTheFailureOf($pollAttempt);
        } else {
            $this->expectTheNextPollAt(
                $this->epochMillisecondsThePollIsDue($filledPayload, $this->clock->epochMilliseconds()),
            );
        }

        return $filledPayload;
    }

    public function pollWhenDueInALongRunningProcess(): void
    {
        $ageMilliseconds = $this->clock->monotonicMilliseconds() - $this->createdAtMonotonicMilliseconds;
        if ($ageMilliseconds > $this->flushIntervalMilliseconds) {
            $this->pollWhenDue();
        }
    }

    public function close(): void
    {
        $this->pollWhenDue();
        $this->isClosed = true;
    }

    private function pollWhenDue(): void
    {
        $isDue = $this->pollDueAtMonotonicMilliseconds !== null
            && $this->clock->monotonicMilliseconds() >= $this->pollDueAtMonotonicMilliseconds;
        if ($this->isClosed || !$isDue) {
            return;
        }

        $refreshLock = $this->store->refreshLockAcquiredWithin(0);
        if ($refreshLock === null) {
            $this->expectTheNextPollAt($this->clock->epochMilliseconds() + $this->pollingInterval->milliseconds());

            return;
        }

        try {
            $pollAttempt = $this->pollAttemptedUnder(
                $refreshLock,
                $this->store->storedPayload(),
                $this->timeoutMilliseconds,
            );
            if ($pollAttempt->freshPayload !== null) {
                $refreshLock->replaceStoredPayload($pollAttempt->freshPayload);
            }
        } finally {
            $refreshLock->release();
        }

        $this->expectTheNextPollAt($pollAttempt->nextPollDueAtEpochMilliseconds);
        $this->logAndReportTheFailureOf($pollAttempt);
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
        if ($now < $pollDueAt || $timeLimitMilliseconds < 1) {
            return new PollAttempt(null, $pollDueAt);
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
            return new PollAttempt(null, $pauseWhileThePollIsUnanswered->untilEpochMilliseconds);
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
            PollAnswerKind::Payload => new PollAttempt(
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
                : new PollAttempt(
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

        return new PollAttempt(null, $pollingPause->untilEpochMilliseconds, $failure, $failedUnrecoverably);
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
