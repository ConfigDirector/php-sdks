<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Evaluation;

use DateTimeImmutable;

final class DatetimeComparison
{
    private const string ECMASCRIPT_DATE_TIME = '/\A(?<year>[+-][0-9]{6}|[0-9]{4})'
        . '(?:-(?<month>[0-9]{2})(?:-(?<day>[0-9]{2}))?)?'
        . '(?:T(?<hour>[0-9]{2}):(?<minute>[0-9]{2})(?::(?<second>[0-9]{2})(?:\.(?<fraction>[0-9]+))?)?'
        . '(?<offset>[Zz]|[+-][0-9]{2}:[0-9]{2})?)?\z/';

    private function __construct() {}

    /**
     * @param list<string> $targets
     */
    public static function matches(string $text, string $operator, array $targets): bool
    {
        $instant = self::millisecondsSinceEpochOf($text);
        $target = $targets === [] ? null : self::millisecondsSinceEpochOf($targets[0]);
        if ($instant === null || $target === null) {
            return false;
        }

        return match ($operator) {
            'is before' => $instant < $target,
            'is after' => $instant > $target,
            default => false,
        };
    }

    private static function millisecondsSinceEpochOf(string $text): ?int
    {
        if (preg_match(self::ECMASCRIPT_DATE_TIME, $text, $match, PREG_UNMATCHED_AS_NULL) !== 1) {
            return null;
        }

        $year = (int) $match['year'];
        $month = (int) ($match['month'] ?? 1);
        $day = (int) ($match['day'] ?? 1);
        $hour = (int) $match['hour'];
        $minute = (int) $match['minute'];
        $second = (int) $match['second'];
        $millisecond = (int) str_pad(substr($match['fraction'] ?? '', 0, 3), 3, '0');
        $offsetMinutes = self::offsetMinutesOf($match['offset']);
        if ($offsetMinutes === null || !self::isPossibleDate($year, $month, $day) || $hour > 23 || $minute > 59
            || $second > 59) {
            return null;
        }

        $secondsSinceEpoch = (new DateTimeImmutable('@0'))
            ->setDate($year, $month, $day)
            ->setTime($hour, $minute, $second)
            ->getTimestamp();

        return $secondsSinceEpoch * 1000 + $millisecond - $offsetMinutes * 60_000;
    }

    private static function offsetMinutesOf(?string $offset): ?int
    {
        if ($offset === null || $offset === 'Z' || $offset === 'z') {
            return 0;
        }

        $hours = (int) substr($offset, 1, 2);
        $minutes = (int) substr($offset, 4, 2);
        if ($hours > 23 || $minutes > 59) {
            return null;
        }

        return ($offset[0] === '-' ? -1 : 1) * ($hours * 60 + $minutes);
    }

    private static function isPossibleDate(int $year, int $month, int $day): bool
    {
        if ($month < 1 || $month > 12 || $day < 1) {
            return false;
        }

        $daysInMonth = (int) (new DateTimeImmutable('@0'))->setDate($year, $month, 1)->format('t');

        return $day <= $daysInMonth;
    }
}
