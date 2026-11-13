<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\LocalStore;

final readonly class PollingPause
{
    public function __construct(
        public string $sessionId,
        public int $recordedAtEpochMilliseconds,
        public int $untilEpochMilliseconds,
        public bool $pollFailed,
        public ?int $refusalStatus,
    ) {}

    public function epochMillisecondsPollsAreHeldUntil(int $nowEpochMilliseconds): int
    {
        $wasRecordedByAClockSetBackSince = $this->recordedAtEpochMilliseconds > $nowEpochMilliseconds;

        return $wasRecordedByAClockSetBackSince ? 0 : $this->untilEpochMilliseconds;
    }

    public function toJson(): string
    {
        return json_encode(
            [
                'sessionId' => $this->sessionId,
                'recordedAt' => $this->recordedAtEpochMilliseconds,
                'until' => $this->untilEpochMilliseconds,
                'pollFailed' => $this->pollFailed,
                'refusalStatus' => $this->refusalStatus,
            ],
            JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    public static function fromJson(string $json): ?self
    {
        try {
            $fileContents = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($fileContents)) {
            return null;
        }

        $sessionId = $fileContents['sessionId'] ?? null;
        $recordedAt = $fileContents['recordedAt'] ?? null;
        $until = $fileContents['until'] ?? null;
        $pollFailed = $fileContents['pollFailed'] ?? null;
        $refusalStatus = $fileContents['refusalStatus'] ?? null;
        $hasEveryField = is_string($sessionId)
            && $sessionId !== ''
            && is_int($recordedAt)
            && is_int($until)
            && is_bool($pollFailed)
            && ($refusalStatus === null || is_int($refusalStatus));

        return $hasEveryField ? new self($sessionId, $recordedAt, $until, $pollFailed, $refusalStatus) : null;
    }
}
