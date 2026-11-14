<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Streaming;

use ConfigDirector\Internal\Polling\RetryAfterHeader;
use ConfigDirector\Internal\ServerSentEvents\EventStreamLimitExceeded;
use ConfigDirector\Internal\ServerSentEvents\EventStreamParser;
use ConfigDirector\Internal\ServerSentEvents\ServerSentEvent;

final class StreamAnswer
{
    private const int REFUSAL_BODY_BYTES_KEPT = 16_384;

    private readonly EventStreamParser $parser;

    private ?int $statusOfTheAnswer = null;

    private ?string $retryAfterHeader = null;

    private string $refusalBody = '';

    private int $bodyBytesReceived = 0;

    private ?EventStreamLimitExceeded $limitExceeded = null;

    private readonly ReceivedEvents $receivedEvents;

    public function __construct(
        private readonly StreamEndpoint $endpoint,
        string $lastEventId,
    ) {
        $this->receivedEvents = new ReceivedEvents();
        $this->parser = new EventStreamParser($lastEventId, $this->receivedEvents->add(...));
    }

    public function isOpen(): bool
    {
        return $this->statusOfTheAnswer !== null && $this->statusOfTheAnswer < 300;
    }

    public function bodyBytesReceived(): int
    {
        return $this->bodyBytesReceived;
    }

    /**
     * @return list<ServerSentEvent>
     */
    public function takeEventsReceived(): array
    {
        return $this->receivedEvents->takeAll();
    }

    public function lastEventId(): string
    {
        return $this->parser->lastEventId();
    }

    public function endedBecause(string $cause, int $nowEpochMilliseconds): StreamEnd
    {
        if ($this->statusOfTheAnswer === null) {
            return StreamEnd::unreachable($cause);
        }
        if (!$this->isOpen()) {
            return StreamEnd::refused(
                $this->statusOfTheAnswer,
                $this->endpoint->refusalDetailIn($this->refusalBody),
                $this->millisecondsNamedByRetryAfter($nowEpochMilliseconds),
            );
        }

        return StreamEnd::dropped($this->limitExceeded?->getMessage() ?? $cause, $this->parser->retryMilliseconds());
    }

    private function millisecondsNamedByRetryAfter(int $nowEpochMilliseconds): ?int
    {
        $seconds = $this->retryAfterHeader === null
            ? null
            : RetryAfterHeader::secondsNamedBy($this->retryAfterHeader, $nowEpochMilliseconds);

        return $seconds === null ? null : max(0, $seconds) * 1_000;
    }

    public function takeInHeaderLine(\CurlHandle $request, string $headerLine): int
    {
        $this->retryAfterHeader = RetryAfterHeader::valueIn($headerLine) ?? $this->retryAfterHeader;
        if (trim($headerLine) === '') {
            $status = curl_getinfo($request, CURLINFO_RESPONSE_CODE);
            $isTheFinalAnswer = $status >= 200;
            $this->statusOfTheAnswer = $isTheFinalAnswer ? $status : null;
        }

        return strlen($headerLine);
    }

    public function takeInBodyChunk(\CurlHandle $request, string $chunk): int
    {
        $this->bodyBytesReceived += strlen($chunk);
        if (!$this->isOpen()) {
            $this->refusalBody = substr($this->refusalBody . $chunk, 0, self::REFUSAL_BODY_BYTES_KEPT);

            return strlen($chunk);
        }

        try {
            $this->parser->feed($chunk);
        } catch (EventStreamLimitExceeded $limitExceeded) {
            $this->limitExceeded = $limitExceeded;

            return -1;
        }

        return strlen($chunk);
    }
}
