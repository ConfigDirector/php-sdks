<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Streaming;

use ConfigDirector\ConfigDirectorConnectionException;
use ConfigDirector\Internal\Connection\ServerSdkPayloadText;
use ConfigDirector\Internal\Identity\SessionId;
use ConfigDirector\Internal\Time\Clock;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

final class StreamingTransport
{
    private const int MILLISECONDS_BETWEEN_HEARTBEATS = 90_000;

    private const int MILLISECONDS_OF_SILENCE_OF_A_DROPPED_STREAM = 45_000;

    private const int MILLISECONDS_AN_ANSWER_MAY_TAKE = 30_000;

    private const int ATTEMPTS_LOGGED_AT_INFO = 5;

    private readonly \CurlMultiHandle $transfers;

    private ?StreamConnection $connection = null;

    private ?\CurlHandle $heartbeatRequest = null;

    private int $connectionDueAtMonotonicMilliseconds;

    private int $connectionStartedAtMonotonicMilliseconds = 0;

    private int $bytesLastArrivedAtMonotonicMilliseconds = 0;

    private int $bodyBytesSeen = 0;

    private int $heartbeatDueAtMonotonicMilliseconds = 0;

    private int $attempt = 0;

    private string $lastEventId = '';

    private bool $isOpen = false;

    private bool $hasStoredAPayloadOfTheOpenStream = false;

    /**
     * @param \Closure(array<array-key, mixed> $payload): bool $storePayloadReceived
     * @param \Closure(): float $randomFraction
     */
    public function __construct(
        private readonly StreamEndpoint $endpoint,
        private readonly \Closure $storePayloadReceived,
        private readonly Clock $clock,
        private readonly \Closure $randomFraction,
        private readonly LoggerInterface $logger,
    ) {
        $this->transfers = curl_multi_init();
        $this->connectionDueAtMonotonicMilliseconds = $clock->monotonicMilliseconds();
    }

    /**
     * @throws ConfigDirectorConnectionException
     */
    public function advance(): void
    {
        $this->connectWhenDue();
        curl_multi_exec($this->transfers, $transfersStillRunning);
        $this->takeInWhatTheStreamReceived();
        $this->endTheRequestsThatFinished();
        $this->endAStreamThatIsSilentForTooLong();
        $this->sendAHeartbeatWhenDue();
    }

    public function hasStoredAPayloadOfTheOpenStream(): bool
    {
        return $this->hasStoredAPayloadOfTheOpenStream;
    }

    public function waitForActivityAtMost(int $milliseconds): void
    {
        $startedAt = hrtime(true);
        $transfersWithActivity = curl_multi_select($this->transfers, $milliseconds / 1_000);
        $remainingMilliseconds = $milliseconds - intdiv(hrtime(true) - $startedAt, 1_000_000);
        if ($transfersWithActivity < 1 && $remainingMilliseconds > 0) {
            usleep($remainingMilliseconds * 1_000);
        }
    }

    public function close(): void
    {
        if ($this->connection !== null) {
            $this->closeTheStreamOf($this->connection);
        }
        if ($this->heartbeatRequest !== null) {
            curl_multi_remove_handle($this->transfers, $this->heartbeatRequest);
            $this->heartbeatRequest = null;
        }
    }

    private function connectWhenDue(): void
    {
        $now = $this->clock->monotonicMilliseconds();
        if ($this->connection !== null || $now < $this->connectionDueAtMonotonicMilliseconds) {
            return;
        }

        $this->connection = new StreamConnection($this->endpoint, SessionId::newlyGenerated(), $this->lastEventId);
        $this->connectionStartedAtMonotonicMilliseconds = $now;
        $this->bodyBytesSeen = 0;
        curl_multi_add_handle($this->transfers, $this->connection->request);
    }

    /**
     * @throws ConfigDirectorConnectionException
     */
    private function takeInWhatTheStreamReceived(): void
    {
        if ($this->connection === null) {
            return;
        }

        $connection = $this->connection;
        $answer = $connection->answer;
        $now = $this->clock->monotonicMilliseconds();
        if ($answer->isOpen() && !$this->isOpen) {
            $this->isOpen = true;
            $this->bytesLastArrivedAtMonotonicMilliseconds = $now;
            $this->heartbeatDueAtMonotonicMilliseconds = $now + self::MILLISECONDS_BETWEEN_HEARTBEATS;
            $this->logger->debug('[StreamingTransport] Connected');
        }
        if ($answer->bodyBytesReceived() !== $this->bodyBytesSeen) {
            $this->bodyBytesSeen = $answer->bodyBytesReceived();
            $this->bytesLastArrivedAtMonotonicMilliseconds = $now;
        }

        foreach ($answer->takeEventsReceived() as $event) {
            $payload = ServerSdkPayloadText::payloadOrWhyItHoldsNone($event->data);
            if (is_string($payload)) {
                $this->logger->error(
                    '[StreamingTransport] Error parsing and dispatching config data update: {cause}',
                    ['cause' => $payload],
                );

                continue;
            }

            if (($this->storePayloadReceived)($payload)) {
                $this->hasStoredAPayloadOfTheOpenStream = true;

                continue;
            }

            $this->logger->warning(
                '[StreamingTransport] Values received from the stream could not be written to the local store. '
                . 'Reconnecting to receive them again.',
            );
            $this->endTheStream($connection, StreamEnd::payloadNotStored());
            $this->lastEventId = '';

            return;
        }
    }

    /**
     * @throws ConfigDirectorConnectionException
     */
    private function endTheRequestsThatFinished(): void
    {
        while (($finishedTransfer = curl_multi_info_read($this->transfers)) !== false) {
            $request = $finishedTransfer['handle'];
            if (!$request instanceof \CurlHandle) {
                continue;
            }

            $curlError = $finishedTransfer['result'] === CURLE_OK ? null : curl_error($request);
            if ($this->heartbeatRequest === $request) {
                $this->endTheHeartbeat($request, $curlError);
            } elseif ($this->connection !== null && $this->connection->request === $request) {
                $this->endTheStream($this->connection, $this->connection->answer->endedBecause(
                    $curlError ?? 'the SDK server closed the stream',
                    $this->clock->epochMilliseconds(),
                ));
            }
        }
    }

    /**
     * @throws ConfigDirectorConnectionException
     */
    private function endAStreamThatIsSilentForTooLong(): void
    {
        if ($this->connection === null) {
            return;
        }

        $now = $this->clock->monotonicMilliseconds();
        [$millisecondsOfSilence, $millisecondsAllowed, $whatDidNotArrive] = $this->isOpen
            ? [
                $now - $this->bytesLastArrivedAtMonotonicMilliseconds,
                self::MILLISECONDS_OF_SILENCE_OF_A_DROPPED_STREAM,
                'no byte',
            ]
            : [
                $now - $this->connectionStartedAtMonotonicMilliseconds,
                self::MILLISECONDS_AN_ANSWER_MAY_TAKE,
                'no answer',
            ];
        if ($millisecondsOfSilence >= $millisecondsAllowed) {
            $this->endTheStream($this->connection, $this->connection->answer->endedBecause(
                sprintf('%s arrived for %d seconds', $whatDidNotArrive, intdiv($millisecondsAllowed, 1_000)),
                $this->clock->epochMilliseconds(),
            ));
        }
    }

    /**
     * @throws ConfigDirectorConnectionException
     */
    private function endTheStream(StreamConnection $connection, StreamEnd $streamEnd): void
    {
        $this->lastEventId = $connection->answer->lastEventId();
        $this->closeTheStreamOf($connection);
        $this->logger->debug('[StreamingTransport] Disconnected', ['cause' => $streamEnd->cause]);

        $this->attempt = match ($streamEnd->kind) {
            StreamEndKind::RefusedUnrecoverably => throw new ConfigDirectorConnectionException(
                sprintf(
                    'Connection failed with status: %d%s. This is an unrecoverable error, will not attempt to '
                    . 'reconnect.',
                    $streamEnd->status,
                    $streamEnd->cause === '' ? '' : " ({$streamEnd->cause})",
                ),
                $streamEnd->status,
            ),
            StreamEndKind::Dropped => 1,
            StreamEndKind::Unreachable => $this->attempt + 1,
            StreamEndKind::Throttled => $this->attempt + 1,
            StreamEndKind::Refused => $this->attempt + 1,
            StreamEndKind::PayloadNotStored => $this->attempt + 1,
        };
        $delayMilliseconds = ReconnectionDelay::millisecondsBeforeAttempt(
            $this->attempt,
            $streamEnd->millisecondsTheServerAskedToWait,
            ($this->randomFraction)(),
        );
        $this->connectionDueAtMonotonicMilliseconds = $this->clock->monotonicMilliseconds() + $delayMilliseconds;
        $this->logger->log(
            $this->attempt > self::ATTEMPTS_LOGGED_AT_INFO ? LogLevel::WARNING : LogLevel::INFO,
            '[StreamingTransport] Scheduling reconnect attempt #{attempt} in {delay}ms.',
            ['attempt' => $this->attempt, 'delay' => $delayMilliseconds],
        );
    }

    private function closeTheStreamOf(StreamConnection $connection): void
    {
        curl_multi_remove_handle($this->transfers, $connection->request);
        $this->connection = null;
        $this->isOpen = false;
        $this->hasStoredAPayloadOfTheOpenStream = false;
    }

    private function sendAHeartbeatWhenDue(): void
    {
        $now = $this->clock->monotonicMilliseconds();
        $isDue = $this->isOpen && $now >= $this->heartbeatDueAtMonotonicMilliseconds;
        if ($this->connection === null || !$isDue || $this->heartbeatRequest !== null) {
            return;
        }

        $this->heartbeatDueAtMonotonicMilliseconds = $now + self::MILLISECONDS_BETWEEN_HEARTBEATS;
        $this->heartbeatRequest = $this->endpoint->requestSendingAHeartbeat($this->connection->sessionId);
        curl_multi_add_handle($this->transfers, $this->heartbeatRequest);
        curl_multi_exec($this->transfers, $transfersStillRunning);
    }

    private function endTheHeartbeat(\CurlHandle $heartbeatRequest, ?string $curlError): void
    {
        curl_multi_remove_handle($this->transfers, $heartbeatRequest);
        $this->heartbeatRequest = null;
        $status = curl_getinfo($heartbeatRequest, CURLINFO_RESPONSE_CODE);
        if ($curlError !== null) {
            $this->logger->debug('[StreamingTransport] Heartbeat failed: {error}', ['error' => $curlError]);
        } elseif ($status < 200 || $status > 299) {
            $this->logger->debug('[StreamingTransport] Heartbeat failed with status: {status}', ['status' => $status]);
        }
    }
}
