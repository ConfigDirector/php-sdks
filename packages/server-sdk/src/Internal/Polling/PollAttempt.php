<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Polling;

use ConfigDirector\ConfigDirectorConnectionException;
use ConfigDirector\Internal\LocalStore\StoredPayload;

final readonly class PollAttempt
{
    private function __construct(
        public int $nextPollDueAtEpochMilliseconds,
        public ?StoredPayload $freshPayload = null,
        public bool $freshPayloadCameFromTheServer = false,
        public ?UnsentPoll $unsentBecause = null,
        public ?ConfigDirectorConnectionException $failure = null,
        public bool $failedUnrecoverably = false,
    ) {}

    public static function unsent(UnsentPoll $because, int $nextPollDueAtEpochMilliseconds): self
    {
        return new self($nextPollDueAtEpochMilliseconds, unsentBecause: $because);
    }

    public static function answeredWithAPayload(
        StoredPayload $receivedPayload,
        int $nextPollDueAtEpochMilliseconds,
    ): self {
        return new self($nextPollDueAtEpochMilliseconds, $receivedPayload, freshPayloadCameFromTheServer: true);
    }

    public static function answeredThatNothingChanged(
        StoredPayload $payloadConfirmedFresh,
        int $nextPollDueAtEpochMilliseconds,
    ): self {
        return new self($nextPollDueAtEpochMilliseconds, $payloadConfirmedFresh);
    }

    public static function failed(
        ConfigDirectorConnectionException $failure,
        bool $failedUnrecoverably,
        int $nextPollDueAtEpochMilliseconds,
    ): self {
        return new self($nextPollDueAtEpochMilliseconds, failure: $failure, failedUnrecoverably: $failedUnrecoverably);
    }
}
