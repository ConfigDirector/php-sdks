<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Telemetry;

final class ReportedTime
{
    private function __construct() {}

    public static function of(int $epochMilliseconds): string
    {
        return gmdate('Y-m-d\TH:i:s', intdiv($epochMilliseconds, 1_000))
            . sprintf('.%03dZ', $epochMilliseconds % 1_000);
    }
}
