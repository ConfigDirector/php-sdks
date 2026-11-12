<?php

declare(strict_types=1);

namespace ConfigDirector\Tests\Internal\Evaluation;

use ConfigDirector\Internal\Evaluation\TextComparison;
use PHPUnit\Framework\TestCase;

final class TextComparisonTest extends TestCase
{
    public function testTextEqualsTheFirstTarget(): void
    {
        self::assertTrue(TextComparison::matches('pro', 'equals', ['pro', 'team']));
    }

    public function testTextEqualsComparesOnlyTheFirstTarget(): void
    {
        self::assertFalse(TextComparison::matches('team', 'equals', ['pro', 'team']));
    }

    public function testTextEqualsSpelledAsASymbol(): void
    {
        self::assertTrue(TextComparison::matches('pro', '=', ['pro']));
    }

    public function testTextEqualsIsCaseSensitive(): void
    {
        self::assertFalse(TextComparison::matches('Pro', 'equals', ['pro']));
    }

    public function testTextEqualsWithNoTargetsDoesNotMatch(): void
    {
        self::assertFalse(TextComparison::matches('', 'equals', []));
    }

    public function testTextDoesNotEqualTheFirstTarget(): void
    {
        self::assertTrue(TextComparison::matches('free', 'does not equal', ['pro']));
    }

    public function testTextDoesNotEqualSpelledAsASymbol(): void
    {
        self::assertTrue(TextComparison::matches('free', '!=', ['pro']));
    }

    public function testTextDoesNotEqualWithNoTargetsDoesNotMatch(): void
    {
        self::assertFalse(TextComparison::matches('pro', 'does not equal', []));
    }

    public function testTextIsOneOfTheTargets(): void
    {
        self::assertTrue(TextComparison::matches('team', 'is one of', ['pro', 'team']));
    }

    public function testTextIsOneOfNoTargetsDoesNotMatch(): void
    {
        self::assertFalse(TextComparison::matches('', 'is one of', []));
    }

    public function testTextOutsideTheTargetsIsNotOneOfThem(): void
    {
        self::assertTrue(TextComparison::matches('free', 'is not one of', ['pro', 'team']));
    }

    public function testIsNotOneOfDoesNotHoldForTextAmongTheTargets(): void
    {
        self::assertFalse(TextComparison::matches('team', 'is not one of', ['pro', 'team']));
    }

    public function testTextIsNotOneOfNoTargetsMatches(): void
    {
        self::assertTrue(TextComparison::matches('pro', 'is not one of', []));
    }

    public function testTextStartsWithAnyTarget(): void
    {
        self::assertTrue(TextComparison::matches('user-42', 'starts with any of', ['admin-', 'user-']));
    }

    public function testTextStartsWithAnyOfNoTargetsDoesNotMatch(): void
    {
        self::assertFalse(TextComparison::matches('user-42', 'starts with any of', []));
    }

    public function testTextWithoutATargetPrefixDoesNotStartWithAnyTarget(): void
    {
        self::assertTrue(TextComparison::matches('guest-42', 'does not start with any of', ['admin-', 'user-']));
    }

    public function testDoesNotStartWithAnyOfDoesNotHoldForTextWithATargetPrefix(): void
    {
        self::assertFalse(TextComparison::matches('user-42', 'does not start with any of', ['admin-', 'user-']));
    }

    public function testTextDoesNotStartWithAnyOfNoTargetsMatches(): void
    {
        self::assertTrue(TextComparison::matches('user-42', 'does not start with any of', []));
    }

    public function testTextEndsWithAnyTarget(): void
    {
        self::assertTrue(TextComparison::matches('ann@acme.com', 'ends with any of', ['@example.com', '@acme.com']));
    }

    public function testTextEndsWithAnyOfNoTargetsDoesNotMatch(): void
    {
        self::assertFalse(TextComparison::matches('ann@acme.com', 'ends with any of', []));
    }

    public function testTextWithoutATargetSuffixDoesNotEndWithAnyTarget(): void
    {
        self::assertTrue(
            TextComparison::matches('ann@other.org', 'does not end with any of', ['@example.com', '@acme.com']),
        );
    }

    public function testDoesNotEndWithAnyOfDoesNotHoldForTextWithATargetSuffix(): void
    {
        self::assertFalse(
            TextComparison::matches('ann@acme.com', 'does not end with any of', ['@example.com', '@acme.com']),
        );
    }

    public function testTextDoesNotEndWithAnyOfNoTargetsMatches(): void
    {
        self::assertTrue(TextComparison::matches('ann@acme.com', 'does not end with any of', []));
    }

    public function testTextWithAnUnknownOperatorDoesNotMatch(): void
    {
        self::assertFalse(TextComparison::matches('pro', 'matches regex', ['pro']));
    }
}
