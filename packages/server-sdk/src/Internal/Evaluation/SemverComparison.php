<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Evaluation;

final class SemverComparison
{
    private const string COERCIBLE_VERSION
        = '/(?:^|[^0-9])([0-9]{1,16})(?:\.([0-9]{1,16}))?(?:\.([0-9]{1,16}))?(?:\z|[^0-9])/';

    private function __construct() {}

    /**
     * @param list<string> $targets
     */
    public static function matches(string $text, string $operator, array $targets): bool
    {
        $version = self::coerce($text);
        $ordering = self::ordering($version, $targets === [] ? null : self::coerce($targets[0]));

        return match ($operator) {
            '=' => $ordering === 0,
            '<' => $ordering !== null && $ordering < 0,
            '<=' => $ordering !== null && $ordering <= 0,
            '>' => $ordering !== null && $ordering > 0,
            '>=' => $ordering !== null && $ordering >= 0,
            'is one of' => self::equalsAny($version, $targets),
            'is not one of' => !self::equalsAny($version, $targets),
            default => false,
        };
    }

    /**
     * @return array{int, int, int}|null
     */
    private static function coerce(string $text): ?array
    {
        if (preg_match(self::COERCIBLE_VERSION, $text, $match, PREG_UNMATCHED_AS_NULL) !== 1) {
            return null;
        }

        return [(int) $match[1], (int) $match[2], (int) $match[3]];
    }

    /**
     * @param array{int, int, int}|null $version
     * @param array{int, int, int}|null $target
     */
    private static function ordering(?array $version, ?array $target): ?int
    {
        if ($version === null || $target === null) {
            return null;
        }

        return $version <=> $target;
    }

    /**
     * @param array{int, int, int}|null $version
     * @param list<string> $targets
     */
    private static function equalsAny(?array $version, array $targets): bool
    {
        foreach ($targets as $target) {
            if (self::ordering($version, self::coerce($target)) === 0) {
                return true;
            }
        }

        return false;
    }
}
