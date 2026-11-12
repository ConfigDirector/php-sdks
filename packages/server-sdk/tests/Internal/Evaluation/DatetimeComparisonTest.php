<?php

declare(strict_types=1);

namespace ConfigDirector\Tests\Internal\Evaluation;

use ConfigDirector\Internal\Evaluation\DatetimeComparison;
use PHPUnit\Framework\TestCase;

final class DatetimeComparisonTest extends TestCase
{
    private const string INSTANT = '2026-01-28T01:25:00.000Z';

    public function testADatetimeIsBeforeALaterTarget(): void
    {
        self::assertTrue(DatetimeComparison::matches('2026-01-28T01:24:59.999Z', 'is before', [self::INSTANT]));
    }

    public function testADatetimeIsNotBeforeItself(): void
    {
        self::assertFalse(DatetimeComparison::matches(self::INSTANT, 'is before', [self::INSTANT]));
    }

    public function testADatetimeIsAfterAnEarlierTarget(): void
    {
        self::assertTrue(DatetimeComparison::matches('2026-01-28T01:25:00.001Z', 'is after', [self::INSTANT]));
    }

    public function testADatetimeIsNotAfterItself(): void
    {
        self::assertFalse(DatetimeComparison::matches(self::INSTANT, 'is after', [self::INSTANT]));
    }

    public function testABareYearIsReadAsTheFirstMomentOfThatYearInUtc(): void
    {
        self::assertFalse(DatetimeComparison::matches('2026', 'is before', ['2026-01-01T00:00:00Z']));
    }

    public function testABareYearIsBeforeTheFirstMillisecondOfThatYear(): void
    {
        self::assertTrue(DatetimeComparison::matches('2026', 'is before', ['2026-01-01T00:00:00.001Z']));
    }

    public function testAYearAndMonthIsReadAsTheFirstOfTheMonth(): void
    {
        self::assertTrue(DatetimeComparison::matches('2026-01', 'is before', ['2026-01-01T00:00:01Z']));
    }

    public function testADateIsReadAsMidnightUtc(): void
    {
        self::assertFalse(DatetimeComparison::matches('2026-01-28', 'is after', ['2026-01-28T00:00:00Z']));
    }

    public function testADatetimeWithoutAnOffsetIsReadAsUtc(): void
    {
        self::assertTrue(DatetimeComparison::matches('2026-01-28T01:25:00.001', 'is after', [self::INSTANT]));
    }

    public function testADatetimeWithoutSecondsIsReadAtTheStartOfTheMinute(): void
    {
        self::assertTrue(DatetimeComparison::matches('2026-01-28T01:24', 'is before', ['2026-01-28T01:24:00.001Z']));
    }

    public function testADatetimeAheadOfUtcIsEarlierThanTheSameClockTimeInUtc(): void
    {
        self::assertTrue(DatetimeComparison::matches('2026-01-28T01:25:00+02:00', 'is before', [self::INSTANT]));
    }

    public function testADatetimeBehindUtcIsLaterThanTheSameClockTimeInUtc(): void
    {
        self::assertTrue(DatetimeComparison::matches('2026-01-28T01:25:00-02:00', 'is after', [self::INSTANT]));
    }

    public function testDigitsBeyondMillisecondsAreTruncated(): void
    {
        self::assertFalse(
            DatetimeComparison::matches('2026-01-28T01:25:00.123999Z', 'is after', ['2026-01-28T01:25:00.123Z']),
        );
    }

    public function testAnImpossibleDayIsUnparseable(): void
    {
        self::assertFalse(DatetimeComparison::matches('2026-02-30', 'is after', [self::INSTANT]));
    }

    public function testALeapDayIsParseable(): void
    {
        self::assertTrue(DatetimeComparison::matches('2024-02-29', 'is before', [self::INSTANT]));
    }

    public function testALeapDayOutsideALeapYearIsUnparseable(): void
    {
        self::assertFalse(DatetimeComparison::matches('2025-02-29', 'is before', [self::INSTANT]));
    }

    public function testAnImpossibleMonthIsUnparseable(): void
    {
        self::assertFalse(DatetimeComparison::matches('2026-13-01', 'is after', [self::INSTANT]));
    }

    public function testAnImpossibleHourIsUnparseable(): void
    {
        self::assertFalse(DatetimeComparison::matches('2026-01-28T24:00', 'is after', [self::INSTANT]));
    }

    public function testAnImpossibleMinuteIsUnparseable(): void
    {
        self::assertFalse(DatetimeComparison::matches('2026-01-28T01:60', 'is after', [self::INSTANT]));
    }

    public function testAnImpossibleSecondIsUnparseable(): void
    {
        self::assertFalse(DatetimeComparison::matches('2026-01-28T01:25:60', 'is after', [self::INSTANT]));
    }

    public function testAnImpossibleOffsetIsUnparseable(): void
    {
        self::assertFalse(DatetimeComparison::matches('2026-01-28T01:24:00+24:00', 'is before', [self::INSTANT]));
    }

    public function testGarbageIsUnparseable(): void
    {
        self::assertFalse(DatetimeComparison::matches('garbage', 'is before', [self::INSTANT]));
    }

    public function testAnEmptyValueIsUnparseable(): void
    {
        self::assertFalse(DatetimeComparison::matches('', 'is before', [self::INSTANT]));
    }

    public function testAnUnparseableTargetDoesNotMatch(): void
    {
        self::assertFalse(DatetimeComparison::matches('2026-01-28', 'is before', ['garbage']));
    }

    public function testADatetimeWithNoTargetsDoesNotMatch(): void
    {
        self::assertFalse(DatetimeComparison::matches('2026-01-28', 'is before', []));
    }

    public function testADatetimeWithAnUnknownOperatorDoesNotMatch(): void
    {
        self::assertFalse(DatetimeComparison::matches('2026-01-28', '<', [self::INSTANT]));
    }
}
