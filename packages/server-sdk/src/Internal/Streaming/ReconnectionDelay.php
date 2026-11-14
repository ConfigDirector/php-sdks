<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Streaming;

final class ReconnectionDelay
{
    private const int MAXIMUM_BACKOFF_EXPONENT = 9;

    private const int SHORTEST_WAIT_THE_SERVER_CAN_ASK_FOR_MILLISECONDS = 1_000;

    private const int LONGEST_WAIT_THE_SERVER_CAN_ASK_FOR_MILLISECONDS = 3_600_000;

    private function __construct() {}

    public static function millisecondsBeforeAttempt(
        int $attempt,
        ?int $millisecondsTheServerAskedToWait,
        float $randomFraction,
    ): int {
        if ($millisecondsTheServerAskedToWait !== null) {
            return max(
                self::SHORTEST_WAIT_THE_SERVER_CAN_ASK_FOR_MILLISECONDS,
                min($millisecondsTheServerAskedToWait, self::LONGEST_WAIT_THE_SERVER_CAN_ASK_FOR_MILLISECONDS),
            );
        }

        $ceilingMilliseconds = 2 ** min($attempt, self::MAXIMUM_BACKOFF_EXPONENT) * 1_000;
        $fixedMilliseconds = intdiv($ceilingMilliseconds, 2);

        return $fixedMilliseconds + (int) ($randomFraction * ($ceilingMilliseconds - $fixedMilliseconds));
    }
}
