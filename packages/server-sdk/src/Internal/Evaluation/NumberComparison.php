<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Evaluation;

final class NumberComparison
{
    private const string DECIMAL_LITERAL_CHARACTERS = '0123456789+-.eE';

    private function __construct() {}

    /**
     * @param list<string> $targets
     */
    public static function matches(mixed $value, string $operator, array $targets): bool
    {
        $numberOperator = NumberOperator::spelled($operator);
        if ($numberOperator === null) {
            return false;
        }

        $number = self::finiteNumberOf($value);
        if ($number === null) {
            return $numberOperator->holdsForAnUnparseableValue();
        }

        $target = $targets === [] ? null : self::finiteNumberIn($targets[0]);
        if ($target === null) {
            return false;
        }

        return $numberOperator->holds($number, $target);
    }

    private static function finiteNumberOf(mixed $value): ?float
    {
        return match (true) {
            is_int($value) => (float) $value,
            is_float($value) => is_finite($value) ? $value : null,
            is_string($value) => self::finiteNumberIn($value),
            default => null,
        };
    }

    private static function finiteNumberIn(string $text): ?float
    {
        $isDecimalLiteral = $text !== ''
            && strspn($text, self::DECIMAL_LITERAL_CHARACTERS) === strlen($text)
            && is_numeric($text);
        if (!$isDecimalLiteral) {
            return null;
        }

        $number = (float) $text;

        return is_finite($number) ? $number : null;
    }
}
