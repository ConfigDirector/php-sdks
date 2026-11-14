<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\ServerSentEvents;

final class EventStreamParser
{
    private const int MAXIMUM_LINE_BYTES = 1_048_576;

    private const int MAXIMUM_EVENT_DATA_BYTES = 16_777_216;

    private const string BYTE_ORDER_MARK = "\xEF\xBB\xBF";

    private string $unfinishedLine = '';

    private bool $lastChunkEndedOnACarriageReturn = false;

    private bool $isTheFirstLine = true;

    private string $eventType = '';

    private ?string $eventData = null;

    private ?int $retryMilliseconds = null;

    /**
     * @param \Closure(ServerSentEvent): void $onEvent
     */
    public function __construct(
        private string $lastEventId,
        private readonly \Closure $onEvent,
    ) {}

    public function feed(string $chunk): void
    {
        if ($chunk === '') {
            return;
        }

        $lineStart = $this->lastChunkEndedOnACarriageReturn && str_starts_with($chunk, "\n") ? 1 : 0;
        $this->lastChunkEndedOnACarriageReturn = str_ends_with($chunk, "\r");
        while (($lineEnd = self::nextLineEndIn($chunk, $lineStart)) !== null) {
            $this->finishLine(substr($chunk, $lineStart, $lineEnd - $lineStart));
            $lineStart = $lineEnd + (substr($chunk, $lineEnd, 2) === "\r\n" ? 2 : 1);
        }

        $this->extendUnfinishedLine(substr($chunk, $lineStart));
    }

    public function lastEventId(): string
    {
        return $this->lastEventId;
    }

    public function retryMilliseconds(): ?int
    {
        return $this->retryMilliseconds;
    }

    private static function nextLineEndIn(string $chunk, int $offset): ?int
    {
        $lineEnd = $offset + strcspn($chunk, "\r\n", $offset);

        return $lineEnd < strlen($chunk) ? $lineEnd : null;
    }

    private function extendUnfinishedLine(string $fragment): void
    {
        if (strlen($this->unfinishedLine) + strlen($fragment) > self::MAXIMUM_LINE_BYTES) {
            throw new EventStreamLimitExceeded('The SDK server sent a line longer than 1 MiB.');
        }

        $this->unfinishedLine .= $fragment;
    }

    private function finishLine(string $fragment): void
    {
        $this->extendUnfinishedLine($fragment);
        $line = $this->unfinishedLine;
        $this->unfinishedLine = '';
        if ($this->isTheFirstLine) {
            $this->isTheFirstLine = false;
            $line = str_starts_with($line, self::BYTE_ORDER_MARK) ? substr($line, strlen(self::BYTE_ORDER_MARK)) : $line;
        }
        if ($line === '') {
            $this->dispatchEvent();

            return;
        }

        [$field, $value] = [...explode(':', $line, 2), ''];
        if (str_starts_with($value, ' ')) {
            $value = substr($value, 1);
        }
        match ($field) {
            'event' => $this->eventType = $value,
            'data' => $this->appendData($value),
            'id' => $this->lastEventId = str_contains($value, "\0") ? $this->lastEventId : $value,
            'retry' => $this->retryMilliseconds = self::millisecondsNamedBy($value) ?? $this->retryMilliseconds,
            default => null,
        };
    }

    private function appendData(string $value): void
    {
        $separator = $this->eventData === null ? '' : "\n";
        if (strlen($this->eventData ?? '') + strlen($separator) + strlen($value) > self::MAXIMUM_EVENT_DATA_BYTES) {
            throw new EventStreamLimitExceeded('The SDK server sent an event larger than 16 MiB.');
        }

        $this->eventData ??= '';
        $this->eventData .= $separator . $value;
    }

    private static function millisecondsNamedBy(string $retryValue): ?int
    {
        if (!ctype_digit($retryValue)) {
            return null;
        }

        $milliseconds = $retryValue + 0;
        $isTooLargeForAnInteger = is_float($milliseconds);

        return $isTooLargeForAnInteger ? null : $milliseconds;
    }

    private function dispatchEvent(): void
    {
        $event = new ServerSentEvent($this->eventType, $this->eventData ?? '');
        $this->eventType = '';
        $this->eventData = null;
        if ($event->data !== '') {
            ($this->onEvent)($event);
        }
    }
}
