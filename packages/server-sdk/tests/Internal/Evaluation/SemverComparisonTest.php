<?php

declare(strict_types=1);

namespace ConfigDirector\Tests\Internal\Evaluation;

use ConfigDirector\Internal\Evaluation\SemverComparison;
use PHPUnit\Framework\TestCase;

final class SemverComparisonTest extends TestCase
{
    public function testAVersionEqualsTheFirstTarget(): void
    {
        self::assertTrue(SemverComparison::matches('1.2.3', '=', ['1.2.3']));
    }

    public function testAVersionMissingItsMinorAndPatchIsCoercedToZeros(): void
    {
        self::assertTrue(SemverComparison::matches('1', '=', ['1.0.0']));
    }

    public function testAVersionMissingItsPatchIsCoercedToZero(): void
    {
        self::assertTrue(SemverComparison::matches('1.0', '=', ['1.0.0']));
    }

    public function testAVersionWithALeadingPrefixIsCoerced(): void
    {
        self::assertTrue(SemverComparison::matches('v2.3.4', '=', ['2.3.4']));
    }

    public function testAVersionDropsAFourthComponent(): void
    {
        self::assertTrue(SemverComparison::matches('1.2.3.4', '=', ['1.2.3']));
    }

    public function testAVersionDropsAPrereleaseSuffix(): void
    {
        self::assertTrue(SemverComparison::matches('0.1.645-a', '=', ['0.1.645']));
    }

    public function testVersionsCompareTheirMajorsNumerically(): void
    {
        self::assertTrue(SemverComparison::matches('10.0.0', '>', ['9.0.0']));
    }

    public function testVersionsCompareTheirMinorsWhenTheMajorsAreEqual(): void
    {
        self::assertTrue(SemverComparison::matches('1.10.0', '>', ['1.9.0']));
    }

    public function testVersionsCompareTheirPatchesWhenTheMajorsAndMinorsAreEqual(): void
    {
        self::assertTrue(SemverComparison::matches('1.2.10', '>', ['1.2.9']));
    }

    public function testAVersionIsLessThanTheTarget(): void
    {
        self::assertTrue(SemverComparison::matches('1.2.3', '<', ['1.3.0']));
    }

    public function testAVersionIsNotLessThanItself(): void
    {
        self::assertFalse(SemverComparison::matches('1.2.3', '<', ['1.2.3']));
    }

    public function testAVersionIsAtMostTheTarget(): void
    {
        self::assertTrue(SemverComparison::matches('1.2.3', '<=', ['1.2.3']));
    }

    public function testAVersionIsNotGreaterThanItself(): void
    {
        self::assertFalse(SemverComparison::matches('1.2.3', '>', ['1.2.3']));
    }

    public function testAVersionIsAtLeastTheTarget(): void
    {
        self::assertTrue(SemverComparison::matches('1.2.3', '>=', ['1.2.3']));
    }

    public function testAVersionIsOneOfTheTargets(): void
    {
        self::assertTrue(SemverComparison::matches('1.0', 'is one of', ['2.0.0', '1.0.0']));
    }

    public function testAVersionOutsideTheTargetsIsNotOneOfThem(): void
    {
        self::assertTrue(SemverComparison::matches('3.0', 'is not one of', ['2.0.0', '1.0.0']));
    }

    public function testIsNotOneOfDoesNotHoldForAVersionAmongTheTargets(): void
    {
        self::assertFalse(SemverComparison::matches('1.0', 'is not one of', ['2.0.0', '1.0.0']));
    }

    public function testAVersionIsNotOneOfNoTargets(): void
    {
        self::assertTrue(SemverComparison::matches('1.0.0', 'is not one of', []));
    }

    public function testAVersionWithNoTargetsIsNotEqual(): void
    {
        self::assertFalse(SemverComparison::matches('1.0.0', '=', []));
    }

    public function testABlankVersionIsNotOneOfTheTargets(): void
    {
        self::assertTrue(SemverComparison::matches('   ', 'is not one of', ['1.0.0']));
    }

    public function testAnEmptyVersionIsNotLessThanAnyTarget(): void
    {
        self::assertFalse(SemverComparison::matches('', '<', ['1.0.0']));
    }

    public function testAVersionThatCannotBeCoercedNeverEqualsATarget(): void
    {
        self::assertFalse(SemverComparison::matches('abc', '=', ['abc']));
    }

    public function testAVersionThatCannotBeCoercedNeverSatisfiesAnOrdering(): void
    {
        self::assertFalse(SemverComparison::matches('abc', '<', ['1.0.0']));
    }

    public function testAVersionThatCannotBeCoercedIsNotOneOfAnything(): void
    {
        self::assertTrue(SemverComparison::matches('abc', 'is not one of', ['abc']));
    }

    public function testATargetThatCannotBeCoercedNeverCompares(): void
    {
        self::assertFalse(SemverComparison::matches('1.0.0', '>', ['abc']));
    }

    public function testAMajorOfSixteenDigitsIsKept(): void
    {
        self::assertTrue(SemverComparison::matches('1234567890123456.0.0', '>', ['1.0.0']));
    }

    public function testAMajorOfSeventeenDigitsIsNotReadAsAMajor(): void
    {
        self::assertFalse(SemverComparison::matches('12345678901234567.0.0', '>', ['1.0.0']));
    }

    public function testAVersionWithAnOperatorOfAnotherTargetTypeDoesNotMatch(): void
    {
        self::assertFalse(SemverComparison::matches('1.0.0', '!=', ['2.0.0']));
    }
}
