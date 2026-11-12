<?php

declare(strict_types=1);

namespace ConfigDirector\Tests\Internal\Evaluation;

use ConfigDirector\Internal\Evaluation\NumberComparison;
use PHPUnit\Framework\TestCase;

final class NumberComparisonTest extends TestCase
{
    public function testANumberEqualsTheFirstTarget(): void
    {
        self::assertTrue(NumberComparison::matches(26, '=', ['26']));
    }

    public function testANumberEqualsATargetWrittenWithAFraction(): void
    {
        self::assertTrue(NumberComparison::matches(26, 'equals', ['26.0']));
    }

    public function testANumberDoesNotEqualTheFirstTarget(): void
    {
        self::assertTrue(NumberComparison::matches(26, '!=', ['25']));
    }

    public function testANumberIsLessThanTheTarget(): void
    {
        self::assertTrue(NumberComparison::matches(25, '<', ['26']));
    }

    public function testANumberIsNotLessThanItself(): void
    {
        self::assertFalse(NumberComparison::matches(26, '<', ['26']));
    }

    public function testANumberIsAtMostTheTarget(): void
    {
        self::assertTrue(NumberComparison::matches(26, '<=', ['26']));
    }

    public function testANumberIsGreaterThanTheTarget(): void
    {
        self::assertTrue(NumberComparison::matches(26.5, '>', ['26']));
    }

    public function testANumberIsNotGreaterThanItself(): void
    {
        self::assertFalse(NumberComparison::matches(26, '>', ['26']));
    }

    public function testANumberIsAtLeastTheTarget(): void
    {
        self::assertTrue(NumberComparison::matches(26, '>=', ['26']));
    }

    public function testANumberInTextIsParsed(): void
    {
        self::assertTrue(NumberComparison::matches('10.5', '>', ['10']));
    }

    public function testANumberInTextWithAnExponentIsParsed(): void
    {
        self::assertTrue(NumberComparison::matches('1e3', '=', ['1000']));
    }

    public function testANumberInTextWithASignIsParsed(): void
    {
        self::assertTrue(NumberComparison::matches('-5', '<', ['0']));
    }

    public function testTextWithTrailingCharactersIsUnparseable(): void
    {
        self::assertFalse(NumberComparison::matches('26abc', '=', ['26']));
    }

    public function testTextWithSurroundingWhitespaceIsUnparseable(): void
    {
        self::assertFalse(NumberComparison::matches(' 42 ', '=', ['42']));
    }

    public function testTextWithDigitSeparatorsIsUnparseable(): void
    {
        self::assertFalse(NumberComparison::matches('1_000', '=', ['1000']));
    }

    public function testTextInHexadecimalIsUnparseable(): void
    {
        self::assertFalse(NumberComparison::matches('0x10', '=', ['16']));
    }

    public function testTextWithAnExponentButNoDigitsIsUnparseable(): void
    {
        self::assertFalse(NumberComparison::matches('1e', '=', ['1']));
    }

    public function testTextSpelledAsInfinityIsUnparseable(): void
    {
        self::assertFalse(NumberComparison::matches('Infinity', '>', ['0']));
    }

    public function testTextBeyondTheFloatRangeIsUnparseable(): void
    {
        self::assertFalse(NumberComparison::matches('1e999', '>', ['0']));
    }

    public function testAnInfiniteFloatIsUnparseable(): void
    {
        self::assertFalse(NumberComparison::matches(INF, '>', ['0']));
    }

    public function testEmptyTextIsUnparseable(): void
    {
        self::assertFalse(NumberComparison::matches('', '=', ['0']));
    }

    public function testAnAbsentValueIsUnparseable(): void
    {
        self::assertFalse(NumberComparison::matches(null, '<', ['10']));
    }

    public function testABooleanIsUnparseable(): void
    {
        self::assertFalse(NumberComparison::matches(true, '=', ['1']));
    }

    public function testAnArrayIsUnparseable(): void
    {
        self::assertFalse(NumberComparison::matches(['26'], '=', ['26']));
    }

    public function testAnUnparseableValueDoesNotEqualAnyNumber(): void
    {
        self::assertTrue(NumberComparison::matches('abc', '!=', ['26']));
    }

    public function testAnUnparseableValueDoesNotEqualAnyNumberWhenSpelledOut(): void
    {
        self::assertTrue(NumberComparison::matches(null, 'does not equal', ['26']));
    }

    public function testAnUnparseableValueDoesNotEqualEvenWithNoTargets(): void
    {
        self::assertTrue(NumberComparison::matches('abc', '!=', []));
    }

    public function testANumberWithNoTargetsDoesNotMatch(): void
    {
        self::assertFalse(NumberComparison::matches(26, '!=', []));
    }

    public function testANumberWithAnUnparseableTargetDoesNotMatch(): void
    {
        self::assertFalse(NumberComparison::matches(26, '!=', ['abc']));
    }

    public function testANumberWithAnUnknownOperatorDoesNotMatch(): void
    {
        self::assertFalse(NumberComparison::matches(26, 'is one of', ['26']));
    }
}
