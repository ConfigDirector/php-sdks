<?php

declare(strict_types=1);

namespace ConfigDirector;

/**
 * Telemetry tuning. It is unlikely these settings need to be adjusted. Keep in mind that
 * ConfigDirector relies on telemetry to provide insights and features related to the configs being
 * used.
 */
final readonly class TelemetryOptions
{
    /**
     * @param int $eventQueueLimit How many telemetry entries one report holds, and how many a
     *     client keeps until it adds them to the local store: 70% of it for evaluations, where
     *     reads of one config that return the same result for the same context are one entry with
     *     a count, and 30% for contexts. The oldest entries over the limit are dropped, and
     *     ConfigDirector keeps a count of dropped events. A number between 100 and 100,000.
     *     Defaults to 5,000.
     * @param int $flushInterval How often a host sends telemetry, in milliseconds. Each client adds
     *     what it evaluated to the local store when it is disposed of, and the first request to
     *     end at least this long after the host's last report sends what every process stored.
     *     Defaults to 30,000 milliseconds (30 seconds).
     */
    public function __construct(
        public int $eventQueueLimit = 5_000,
        public int $flushInterval = 30_000,
    ) {}
}
