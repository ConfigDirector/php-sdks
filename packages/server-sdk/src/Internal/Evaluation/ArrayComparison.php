<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Evaluation;

final class ArrayComparison
{
    private function __construct() {}

    /**
     * @param list<string> $targets
     */
    public static function matches(mixed $value, string $operator, array $targets): bool
    {
        $containsAnyTarget = is_array($value) && array_is_list($value) && self::containsAny($value, $targets);

        return match ($operator) {
            'contains any of' => $containsAnyTarget,
            'does not contain any of' => !$containsAnyTarget,
            default => false,
        };
    }

    /**
     * @param list<mixed> $elements
     * @param list<string> $targets
     */
    private static function containsAny(array $elements, array $targets): bool
    {
        foreach ($elements as $element) {
            $text = ScalarText::of($element);
            if ($text !== null && in_array($text, $targets, true)) {
                return true;
            }
        }

        return false;
    }
}
