<?php

declare(strict_types=1);

namespace ConfigDirector\Tests\Internal\Evaluation;

use ConfigDirector\Internal\Evaluation\ArrayComparison;
use PHPUnit\Framework\TestCase;

final class ArrayComparisonTest extends TestCase
{
    public function testAnArrayContainsAnyTarget(): void
    {
        self::assertTrue(ArrayComparison::matches(['red', 'blue'], 'contains any of', ['green', 'blue']));
    }

    public function testAnArrayWithoutATargetDoesNotContainAny(): void
    {
        self::assertFalse(ArrayComparison::matches(['red'], 'contains any of', ['green', 'blue']));
    }

    public function testAnArrayDoesNotContainAnyTarget(): void
    {
        self::assertTrue(ArrayComparison::matches(['red'], 'does not contain any of', ['green', 'blue']));
    }

    public function testAnArrayHoldingATargetFailsDoesNotContainAny(): void
    {
        self::assertFalse(ArrayComparison::matches(['red', 'blue'], 'does not contain any of', ['blue']));
    }

    public function testNumericElementsAreComparedByTheirText(): void
    {
        self::assertTrue(ArrayComparison::matches([1, 2], 'contains any of', ['1']));
    }

    public function testBooleanElementsAreComparedByTheirText(): void
    {
        self::assertTrue(ArrayComparison::matches([true], 'contains any of', ['true']));
    }

    public function testElementsWithoutATextFormAreDropped(): void
    {
        self::assertFalse(ArrayComparison::matches([null, [], ['k' => 'v']], 'contains any of', ['']));
    }

    public function testAnArrayOfElementsWithoutATextFormContainsNoTarget(): void
    {
        self::assertTrue(ArrayComparison::matches([null], 'does not contain any of', ['']));
    }

    public function testAnArrayContainsAnyOfNoTargetsDoesNotMatch(): void
    {
        self::assertFalse(ArrayComparison::matches(['red'], 'contains any of', []));
    }

    public function testAnArrayDoesNotContainAnyOfNoTargetsMatches(): void
    {
        self::assertTrue(ArrayComparison::matches(['red'], 'does not contain any of', []));
    }

    public function testAScalarValueContainsNothing(): void
    {
        self::assertFalse(ArrayComparison::matches('red', 'contains any of', ['red']));
    }

    public function testAScalarValueDoesNotContainAnyTarget(): void
    {
        self::assertTrue(ArrayComparison::matches('red', 'does not contain any of', ['red']));
    }

    public function testAnObjectContainsNothing(): void
    {
        self::assertFalse(ArrayComparison::matches(['color' => 'red'], 'contains any of', ['red']));
    }

    public function testAnAbsentValueContainsNothing(): void
    {
        self::assertFalse(ArrayComparison::matches(null, 'contains any of', ['']));
    }

    public function testAnArrayWithAnUnknownOperatorDoesNotMatch(): void
    {
        self::assertFalse(ArrayComparison::matches(['red'], 'is one of', ['red']));
    }
}
