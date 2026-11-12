<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Evaluation;

final class ScalarText
{
    private const int DIGITS_THAT_ALWAYS_READ_BACK_THE_SAME = 17;

    private const int LAST_POINT_POSITION_SPELLED_OUT = 21;

    private const int FIRST_POINT_POSITION_SPELLED_OUT = -5;

    private function __construct() {}

    public static function of(mixed $value): ?string
    {
        return match (true) {
            is_string($value) => $value,
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value) => (string) $value,
            is_float($value) => self::ofFloat($value),
            default => null,
        };
    }

    private static function ofFloat(float $number): string
    {
        return match (true) {
            is_nan($number) => 'NaN',
            is_infinite($number) => $number > 0 ? 'Infinity' : '-Infinity',
            $number === 0.0 => '0',
            $number < 0 => '-' . self::ofPositiveFloat(-$number),
            default => self::ofPositiveFloat($number),
        };
    }

    private static function ofPositiveFloat(float $number): string
    {
        [$digits, $pointPosition] = self::shortestDigitsAndPointPosition($number);
        $digitCount = strlen($digits);

        return match (true) {
            $digitCount <= $pointPosition && $pointPosition <= self::LAST_POINT_POSITION_SPELLED_OUT
                => $digits . str_repeat('0', $pointPosition - $digitCount),
            0 < $pointPosition && $pointPosition <= self::LAST_POINT_POSITION_SPELLED_OUT
                => substr($digits, 0, $pointPosition) . '.' . substr($digits, $pointPosition),
            self::FIRST_POINT_POSITION_SPELLED_OUT <= $pointPosition && $pointPosition <= 0
                => '0.' . str_repeat('0', -$pointPosition) . $digits,
            default => self::exponentForm($digits, $pointPosition - 1),
        };
    }

    /**
     * @return array{string, int}
     */
    private static function shortestDigitsAndPointPosition(float $number): array
    {
        for ($precision = 0; $precision < self::DIGITS_THAT_ALWAYS_READ_BACK_THE_SAME - 1; $precision++) {
            $scientific = sprintf('%.' . $precision . 'e', $number);
            if ((float) $scientific === $number) {
                return self::digitsAndPointPosition($scientific);
            }
        }

        return self::digitsAndPointPosition(
            sprintf('%.' . (self::DIGITS_THAT_ALWAYS_READ_BACK_THE_SAME - 1) . 'e', $number),
        );
    }

    /**
     * @return array{string, int}
     */
    private static function digitsAndPointPosition(string $scientific): array
    {
        [$mantissa, $exponent] = explode('e', $scientific, 2);

        return [str_replace('.', '', $mantissa), (int) $exponent + 1];
    }

    private static function exponentForm(string $digits, int $exponent): string
    {
        $mantissa = strlen($digits) === 1 ? $digits : $digits[0] . '.' . substr($digits, 1);

        return $mantissa . 'e' . ($exponent < 0 ? '-' : '+') . abs($exponent);
    }
}
