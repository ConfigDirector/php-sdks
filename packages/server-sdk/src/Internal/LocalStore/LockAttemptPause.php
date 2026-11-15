<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\LocalStore;

final class LockAttemptPause
{
    private const int LONGEST_PAUSE_AFTER_THE_FIRST_ATTEMPT_MICROSECONDS = 250;

    private const int ATTEMPTS_AFTER_WHICH_THE_PAUSE_STOPS_DOUBLING = 3;

    private function __construct() {}

    public static function microsecondsAfterAttempt(int $attempt, float $randomFraction): int
    {
        $timesThePauseHasDoubled = min($attempt, self::ATTEMPTS_AFTER_WHICH_THE_PAUSE_STOPS_DOUBLING) - 1;
        $longestPauseMicroseconds = self::LONGEST_PAUSE_AFTER_THE_FIRST_ATTEMPT_MICROSECONDS
            * 2 ** $timesThePauseHasDoubled;
        $fixedMicroseconds = intdiv($longestPauseMicroseconds, 2);

        return $fixedMicroseconds + (int) ($randomFraction * ($longestPauseMicroseconds - $fixedMicroseconds));
    }
}
