<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Value;

use ConfigDirector\EvaluationReason;

final readonly class TypedValue
{
    private const string DECIMAL_CHARACTERS = '0123456789+-.eE';

    private const string WHOLE_NUMBER_CHARACTERS = '0123456789+-';

    private const array CONFIG_TYPES_NOT_READABLE_AS_STRING = ['boolean', 'integer', 'float'];

    private const float FIRST_FLOAT_ABOVE_THE_INTEGER_RANGE = 9223372036854775808.0;

    /**
     * @param bool|string|int|float|array<array-key, mixed> $value
     */
    private function __construct(public bool|string|int|float|array $value) {}

    public static function fromServedText(
        ?string $servedText,
        string $configType,
        InCodeDefaultValueType $inCodeDefaultValueType,
    ): self|EvaluationReason {
        if ($servedText === null || $servedText === '') {
            return EvaluationReason::ValueMissing;
        }

        return match ($inCodeDefaultValueType) {
            InCodeDefaultValueType::Boolean => self::booleanFrom($servedText),
            InCodeDefaultValueType::String => self::stringFrom($servedText, $configType),
            InCodeDefaultValueType::Integer => self::integerFrom($servedText),
            InCodeDefaultValueType::Float => self::floatFrom($servedText),
            InCodeDefaultValueType::Array => self::arrayFrom($servedText),
        };
    }

    private static function booleanFrom(string $servedText): self|EvaluationReason
    {
        return match (strtolower($servedText)) {
            'true' => new self(true),
            'false' => new self(false),
            default => EvaluationReason::InvalidBoolean,
        };
    }

    private static function stringFrom(string $servedText, string $configType): self|EvaluationReason
    {
        return in_array($configType, self::CONFIG_TYPES_NOT_READABLE_AS_STRING, true)
            ? EvaluationReason::TypeMismatch
            : new self($servedText);
    }

    private static function integerFrom(string $servedText): self|EvaluationReason
    {
        $number = self::finiteNumberFrom($servedText);
        if (is_int($number)) {
            return new self($number);
        }
        $isWrittenAsAWholeNumber = strspn($servedText, self::WHOLE_NUMBER_CHARACTERS) === strlen($servedText);
        if ($number === null || $isWrittenAsAWholeNumber) {
            return EvaluationReason::InvalidNumber;
        }
        if (floor($number) !== $number || !self::fitsThePhpIntegerRange($number)) {
            return EvaluationReason::InvalidNumber;
        }

        return new self((int) $number);
    }

    private static function floatFrom(string $servedText): self|EvaluationReason
    {
        $number = self::finiteNumberFrom($servedText);

        return $number === null ? EvaluationReason::InvalidNumber : new self((float) $number);
    }

    private static function arrayFrom(string $servedText): self|EvaluationReason
    {
        try {
            $decoded = json_decode($servedText, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return EvaluationReason::InvalidJson;
        }

        return is_array($decoded) ? new self($decoded) : EvaluationReason::InvalidJson;
    }

    private static function finiteNumberFrom(string $servedText): int|float|null
    {
        $holdsOnlyDecimalCharacters = strspn($servedText, self::DECIMAL_CHARACTERS) === strlen($servedText);
        if (!$holdsOnlyDecimalCharacters || !is_numeric($servedText)) {
            return null;
        }

        $number = $servedText + 0;

        return is_float($number) && !is_finite($number) ? null : $number;
    }

    private static function fitsThePhpIntegerRange(float $wholeNumber): bool
    {
        return $wholeNumber >= -self::FIRST_FLOAT_ABOVE_THE_INTEGER_RANGE
            && $wholeNumber < self::FIRST_FLOAT_ABOVE_THE_INTEGER_RANGE;
    }
}
