<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Streaming;

final readonly class StreamEnd
{
    private const int TOO_MANY_REQUESTS_STATUS = 429;

    private function __construct(
        public StreamEndKind $kind,
        public string $cause,
        public ?int $status = null,
        public ?int $millisecondsTheServerAskedToWait = null,
    ) {}

    public static function unreachable(string $cause): self
    {
        return new self(StreamEndKind::Unreachable, $cause);
    }

    public static function refused(int $status, string $detail, ?int $millisecondsNamedByRetryAfter): self
    {
        return match (true) {
            $status === self::TOO_MANY_REQUESTS_STATUS => new self(
                StreamEndKind::Throttled,
                $detail,
                $status,
                $millisecondsNamedByRetryAfter,
            ),
            $status >= 400 && $status < 500 => new self(StreamEndKind::RefusedUnrecoverably, $detail, $status),
            default => new self(StreamEndKind::Refused, $detail, $status),
        };
    }

    public static function dropped(string $cause, ?int $millisecondsNamedByTheStream): self
    {
        return new self(StreamEndKind::Dropped, $cause, null, $millisecondsNamedByTheStream);
    }

    public static function payloadNotStored(): self
    {
        return new self(StreamEndKind::PayloadNotStored, 'a payload could not be written to the local store');
    }
}
