<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Evaluation;

final class TraitPointer
{
    private const string INVALID_ESCAPE = '/~(?![01])/';

    private const array ESCAPES = ['~1' => '/', '~0' => '~'];

    private function __construct() {}

    /**
     * @param array<array-key, mixed> $traits
     */
    public static function valueAt(string $pointer, array $traits): mixed
    {
        if (!str_starts_with($pointer, '/')) {
            return null;
        }

        $current = $traits;
        foreach (explode('/', substr($pointer, 1)) as $encodedToken) {
            if (preg_match(self::INVALID_ESCAPE, $encodedToken) === 1) {
                return null;
            }
            $current = self::memberOf($current, strtr($encodedToken, self::ESCAPES));
            if ($current === null) {
                return null;
            }
        }

        return $current;
    }

    private static function memberOf(mixed $container, string $token): mixed
    {
        if (!is_array($container)) {
            return null;
        }
        if (!array_is_list($container)) {
            return $container[$token] ?? null;
        }
        if (!self::isArrayIndex($token)) {
            return null;
        }

        return $container[(int) $token] ?? null;
    }

    private static function isArrayIndex(string $token): bool
    {
        $isDigitsOnly = $token !== '' && strspn($token, '0123456789') === strlen($token);

        return $isDigitsOnly && ($token === '0' || $token[0] !== '0');
    }
}
