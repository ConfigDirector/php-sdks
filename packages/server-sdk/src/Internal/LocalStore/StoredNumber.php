<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\LocalStore;

final class StoredNumber
{
    private const int LATEST_EPOCH_MILLISECONDS_A_CLOCK_SHOWS = 1_000_000_000_000_000;

    private const int LARGEST_COUNT_ADDED_UP_WITHOUT_OVERFLOW = 1_000_000_000_000;

    private function __construct() {}

    /**
     * @phpstan-assert-if-true int<0, max> $number
     */
    public static function isEpochMilliseconds(mixed $number): bool
    {
        return is_int($number) && $number >= 0 && $number <= self::LATEST_EPOCH_MILLISECONDS_A_CLOCK_SHOWS;
    }

    /**
     * @phpstan-assert-if-true int<0, max> $number
     */
    public static function isCount(mixed $number): bool
    {
        return is_int($number) && $number >= 0 && $number <= self::LARGEST_COUNT_ADDED_UP_WITHOUT_OVERFLOW;
    }
}
