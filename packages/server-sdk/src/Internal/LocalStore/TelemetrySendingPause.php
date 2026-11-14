<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\LocalStore;

final readonly class TelemetrySendingPause
{
    public function __construct(
        private int $recordedAtEpochMilliseconds,
        private int $untilEpochMilliseconds,
        private ?int $refusalStatus,
    ) {}

    public function epochMillisecondsSendingIsHeldUntil(int $nowEpochMilliseconds): int
    {
        $wasRecordedByAClockSetBackSince = $this->recordedAtEpochMilliseconds > $nowEpochMilliseconds;

        return $wasRecordedByAClockSetBackSince ? 0 : $this->untilEpochMilliseconds;
    }

    public function toJson(): string
    {
        return json_encode(
            [
                'recordedAt' => $this->recordedAtEpochMilliseconds,
                'until' => $this->untilEpochMilliseconds,
                'refusalStatus' => $this->refusalStatus,
            ],
            JSON_THROW_ON_ERROR,
        );
    }

    public static function fromJson(string $json): ?self
    {
        $fileContents = json_decode($json, associative: true);
        if (!is_array($fileContents)) {
            return null;
        }

        $recordedAt = $fileContents['recordedAt'] ?? null;
        $until = $fileContents['until'] ?? null;
        $refusalStatus = $fileContents['refusalStatus'] ?? null;

        $isWhatTheSdkStores = StoredNumber::isEpochMilliseconds($recordedAt)
            && StoredNumber::isEpochMilliseconds($until)
            && ($refusalStatus === null || is_int($refusalStatus));

        return $isWhatTheSdkStores
            ? new self($recordedAt, $until, $refusalStatus)
            : null;
    }
}
