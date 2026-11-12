<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Evaluation;

enum NumberOperator
{
    case Equals;
    case DoesNotEqual;
    case LessThan;
    case AtMost;
    case GreaterThan;
    case AtLeast;

    public static function spelled(string $operator): ?self
    {
        return match ($operator) {
            '=', 'equals' => self::Equals,
            '!=', 'does not equal' => self::DoesNotEqual,
            '<' => self::LessThan,
            '<=' => self::AtMost,
            '>' => self::GreaterThan,
            '>=' => self::AtLeast,
            default => null,
        };
    }

    public function holds(float $number, float $target): bool
    {
        return match ($this) {
            self::Equals => $number === $target,
            self::DoesNotEqual => $number !== $target,
            self::LessThan => $number < $target,
            self::AtMost => $number <= $target,
            self::GreaterThan => $number > $target,
            self::AtLeast => $number >= $target,
        };
    }

    public function holdsForAnUnparseableValue(): bool
    {
        return match ($this) {
            self::DoesNotEqual => true,
            self::Equals, self::LessThan, self::AtMost, self::GreaterThan, self::AtLeast => false,
        };
    }
}
