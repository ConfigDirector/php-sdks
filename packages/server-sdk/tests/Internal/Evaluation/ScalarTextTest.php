<?php

declare(strict_types=1);

namespace ConfigDirector\Tests\Internal\Evaluation;

use ConfigDirector\Internal\Evaluation\ScalarText;
use PHPUnit\Framework\TestCase;

final class ScalarTextTest extends TestCase
{
    public function testTheTextOfAStringIsItself(): void
    {
        self::assertSame('abc', ScalarText::of('abc'));
    }

    public function testTheTextOfTrueIsItsJsonSpelling(): void
    {
        self::assertSame('true', ScalarText::of(true));
    }

    public function testTheTextOfFalseIsItsJsonSpelling(): void
    {
        self::assertSame('false', ScalarText::of(false));
    }

    public function testTheTextOfAnIntegerIsItsDigits(): void
    {
        self::assertSame('26', ScalarText::of(26));
    }

    public function testTheTextOfANegativeIntegerKeepsItsSign(): void
    {
        self::assertSame('-3', ScalarText::of(-3));
    }

    public function testTheTextOfAWholeFloatHasNoFraction(): void
    {
        self::assertSame('26', ScalarText::of(26.0));
    }

    public function testTheTextOfAFractionalNumberKeepsItsFraction(): void
    {
        self::assertSame('26.5', ScalarText::of(26.5));
    }

    public function testTheTextOfANegativeFloatKeepsItsSign(): void
    {
        self::assertSame('-0.25', ScalarText::of(-0.25));
    }

    public function testTheTextOfNegativeZeroIsZero(): void
    {
        self::assertSame('0', ScalarText::of(-0.0));
    }

    public function testTheTextOfAFractionalNumberKeepsEveryDigitItNeedsToReadBackTheSame(): void
    {
        self::assertSame('0.30000000000000004', ScalarText::of(0.30000000000000004));
    }

    public function testTheTextOfALargeFractionalNumberIsNotInExponentForm(): void
    {
        self::assertSame('1234567.5', ScalarText::of(1234567.5));
    }

    public function testTheTextOfALargeWholeFloatIsSpelledOut(): void
    {
        self::assertSame('100000000000000000000', ScalarText::of(1e20));
    }

    public function testNullHasNoText(): void
    {
        self::assertNull(ScalarText::of(null));
    }

    public function testAnArrayHasNoText(): void
    {
        self::assertNull(ScalarText::of(['a']));
    }

    public function testAnObjectHasNoText(): void
    {
        self::assertNull(ScalarText::of(['a' => 'b']));
    }
}
