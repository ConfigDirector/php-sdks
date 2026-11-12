<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Evaluation;

final class TextComparison
{
    private function __construct() {}

    /**
     * @param list<string> $targets
     */
    public static function matches(string $text, string $operator, array $targets): bool
    {
        return match ($operator) {
            '=', 'equals' => $targets !== [] && $text === $targets[0],
            '!=', 'does not equal' => $targets !== [] && $text !== $targets[0],
            'is one of' => in_array($text, $targets, true),
            'is not one of' => !in_array($text, $targets, true),
            'starts with any of' => self::startsWithAny($text, $targets),
            'does not start with any of' => !self::startsWithAny($text, $targets),
            'ends with any of' => self::endsWithAny($text, $targets),
            'does not end with any of' => !self::endsWithAny($text, $targets),
            default => false,
        };
    }

    /**
     * @param list<string> $targets
     */
    private static function startsWithAny(string $text, array $targets): bool
    {
        foreach ($targets as $target) {
            if (str_starts_with($text, $target)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $targets
     */
    private static function endsWithAny(string $text, array $targets): bool
    {
        foreach ($targets as $target) {
            if (str_ends_with($text, $target)) {
                return true;
            }
        }

        return false;
    }
}
