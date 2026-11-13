<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Value;

final readonly class EncodedNativeValue
{
    private const int DIGITS_AFTER_THE_POINT_THAT_ALWAYS_READ_BACK_EXACTLY = 16;

    private const int MAXIMUM_JSON_DEPTH = 512;

    private const int JSON_TEXT_FLAGS = JSON_THROW_ON_ERROR
        | JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_PRESERVE_ZERO_FRACTION;

    private function __construct(
        public string $configType,
        public string $servedText,
    ) {}

    /**
     * @throws UnservableValue
     */
    public static function of(mixed $value, string $configKey): self
    {
        return match (true) {
            is_bool($value) => new self('boolean', $value ? 'true' : 'false'),
            is_int($value) => new self('integer', (string) $value),
            is_float($value) && is_finite($value) => new self('float', self::plainDecimalTextOf($value)),
            is_float($value) => throw new UnservableValue(sprintf(
                "Invalid value for '%s': %s is not a finite number.",
                $configKey,
                var_export($value, true),
            )),
            is_string($value) => new self('string', $value),
            is_array($value), $value instanceof \stdClass => new self('json', self::jsonTextOf($value, $configKey)),
            default => throw new UnservableValue(sprintf(
                "Invalid value for '%s': %s. Use a bool, an int, a float, a string or an array.",
                $configKey,
                self::descriptionOf($value),
            )),
        };
    }

    private static function plainDecimalTextOf(float $value): string
    {
        [$mantissa, $exponent] = explode('e', self::shortestScientificTextOf($value));
        $sign = str_starts_with($mantissa, '-') ? '-' : '';
        $significantDigits = str_replace(['-', '.'], '', $mantissa);
        $digitsBeforeThePoint = (int) $exponent + 1;
        $significantDigitCount = strlen($significantDigits);

        return $sign . match (true) {
            $digitsBeforeThePoint <= 0 => '0.' . str_repeat('0', -$digitsBeforeThePoint) . $significantDigits,
            $digitsBeforeThePoint >= $significantDigitCount => $significantDigits
                . str_repeat('0', $digitsBeforeThePoint - $significantDigitCount),
            default => substr($significantDigits, 0, $digitsBeforeThePoint)
                . '.' . substr($significantDigits, $digitsBeforeThePoint),
        };
    }

    private static function shortestScientificTextOf(float $value): string
    {
        for ($digitsAfterThePoint = 0; $digitsAfterThePoint < self::DIGITS_AFTER_THE_POINT_THAT_ALWAYS_READ_BACK_EXACTLY; $digitsAfterThePoint++) {
            $scientificText = sprintf("%.{$digitsAfterThePoint}e", $value);
            if ((float) $scientificText === $value) {
                return $scientificText;
            }
        }

        return sprintf('%.' . self::DIGITS_AFTER_THE_POINT_THAT_ALWAYS_READ_BACK_EXACTLY . 'e', $value);
    }

    /**
     * @param array<array-key, mixed>|\stdClass $value
     */
    private static function jsonTextOf(array|\stdClass $value, string $configKey): string
    {
        self::rejectUnservableMembersOf($value, $configKey, '', 1);
        try {
            return json_encode($value, self::JSON_TEXT_FLAGS, self::MAXIMUM_JSON_DEPTH);
        } catch (\JsonException $exception) {
            throw new UnservableValue(
                "Invalid value for '{$configKey}': {$exception->getMessage()}.",
                previous: $exception,
            );
        }
    }

    /**
     * @param array<array-key, mixed>|\stdClass $jsonContainer
     */
    private static function rejectUnservableMembersOf(
        array|\stdClass $jsonContainer,
        string $configKey,
        string $jsonPath,
        int $depth,
    ): void {
        if ($depth > self::MAXIMUM_JSON_DEPTH) {
            return;
        }

        $isAList = is_array($jsonContainer) && array_is_list($jsonContainer);
        foreach ((array) $jsonContainer as $member => $memberContents) {
            $memberPath = match (true) {
                $isAList => "{$jsonPath}[{$member}]",
                $jsonPath === '' => (string) $member,
                default => "{$jsonPath}.{$member}",
            };
            if (is_array($memberContents) || $memberContents instanceof \stdClass) {
                self::rejectUnservableMembersOf($memberContents, $configKey, $memberPath, $depth + 1);
            }

            $problem = match (true) {
                is_float($memberContents) && !is_finite($memberContents) => sprintf(
                    '%s is not a finite number',
                    var_export($memberContents, true),
                ),
                is_object($memberContents) && !$memberContents instanceof \stdClass,
                is_resource($memberContents) => self::descriptionOf($memberContents) . ' cannot be encoded as JSON',
                default => null,
            };
            if ($problem !== null) {
                throw new UnservableValue(
                    "Invalid value for '{$configKey}' at '{$memberPath}': {$problem}. JSON contents can hold bools, "
                    . 'ints, finite floats, strings, null, arrays and stdClass objects.',
                );
            }
        }
    }

    private static function descriptionOf(mixed $value): string
    {
        return is_object($value) ? 'an object of class ' . $value::class : get_debug_type($value);
    }
}
