<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Value;

use ConfigDirector\ConfigDirectorValidationException;

final readonly class InCodeDefaultValue
{
    /**
     * @param bool|string|int|float|array<array-key, mixed> $value
     */
    private function __construct(
        public bool|string|int|float|array $value,
        public InCodeDefaultValueType $type,
    ) {}

    public static function from(mixed $value): self
    {
        return match (true) {
            is_bool($value) => new self($value, InCodeDefaultValueType::Boolean),
            is_string($value) => new self($value, InCodeDefaultValueType::String),
            is_int($value) => new self($value, InCodeDefaultValueType::Integer),
            is_float($value) => new self($value, InCodeDefaultValueType::Float),
            is_array($value) => new self($value, InCodeDefaultValueType::Array),
            default => throw new ConfigDirectorValidationException(sprintf(
                'Invalid in-code default value of type %s. The in-code default value for a config must be a bool, '
                . 'a string, an int, a float or an array.',
                get_debug_type($value),
            )),
        };
    }
}
