<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Polling;

use ConfigDirector\ConfigDirectorConnectionException;
use ConfigDirector\Internal\LocalStore\StoredPayload;

final readonly class PollAttempt
{
    public function __construct(
        public ?StoredPayload $freshPayload,
        public int $nextPollDueAtEpochMilliseconds,
        public ?ConfigDirectorConnectionException $failure = null,
        public bool $failedUnrecoverably = false,
    ) {}
}
