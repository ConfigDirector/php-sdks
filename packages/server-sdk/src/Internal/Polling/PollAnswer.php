<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Polling;

final readonly class PollAnswer
{
    /**
     * @param array<array-key, mixed> $payload
     */
    private function __construct(
        public PollAnswerKind $kind,
        public array $payload = [],
        public ?int $status = null,
        public string $detail = '',
        public ?string $retryAfterHeader = null,
    ) {}

    /**
     * @param array<array-key, mixed> $payload
     */
    public static function payload(array $payload): self
    {
        return new self(PollAnswerKind::Payload, $payload);
    }

    public static function unchanged(): self
    {
        return new self(PollAnswerKind::Unchanged);
    }

    public static function refused(int $status, string $detail, ?string $retryAfterHeader): self
    {
        $kind = match (true) {
            $status === 429 => PollAnswerKind::Throttled,
            $status >= 400 && $status < 500 => PollAnswerKind::RefusedUnrecoverably,
            default => PollAnswerKind::Refused,
        };

        return new self($kind, status: $status, detail: $detail, retryAfterHeader: $retryAfterHeader);
    }

    public static function unanswered(string $detail): self
    {
        return new self(PollAnswerKind::Unanswered, detail: $detail);
    }

    public static function timedOut(string $detail): self
    {
        return new self(PollAnswerKind::TimedOut, detail: $detail);
    }

    public static function unreadable(string $detail): self
    {
        return new self(PollAnswerKind::Unreadable, detail: $detail);
    }
}
