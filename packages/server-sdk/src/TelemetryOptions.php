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
     * @param int $eventQueueLimit How many telemetry events are kept until they are sent. Events
     *     over the limit are dropped, and ConfigDirector keeps a count of dropped events. A number
     *     between 100 and 100,000. Defaults to 5,000.
     * @param int $flushInterval How often telemetry is sent, in milliseconds. Defaults to 30,000
     *     milliseconds (30 seconds).
     */
    public function __construct(
        public int $eventQueueLimit = 5_000,
        public int $flushInterval = 30_000,
    ) {}
}
