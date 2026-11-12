<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Evaluation;

final class AssignedPercentage
{
    private const int SEED = 0x397832987;

    private const int REACHABLE_TENTHS_OF_A_PERCENT = 1000;

    private function __construct() {}

    public static function of(string $configId, string $identifier, string $contextKind): float
    {
        $hashInput = $identifier . '-' . $configId;
        if ($contextKind !== EvaluationContext::USER_CONTEXT_KIND) {
            $hashInput .= ':' . $contextKind;
        }

        return self::unsignedRemainder(Rapidhash::hash($hashInput, self::SEED), self::REACHABLE_TENTHS_OF_A_PERCENT)
            / 10.0;
    }

    private static function unsignedRemainder(int $bits, int $divisor): int
    {
        $half = ($bits >> 1) & PHP_INT_MAX;

        return (2 * ($half % $divisor) + ($bits & 1)) % $divisor;
    }
}
