<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Client;

final class ContextKind
{
    private const string ALLOWED_CHARACTERS = 'abcdefghijklmnopqrstuvwxyz0123456789-_';

    private const int MAXIMUM_LENGTH = 64;

    private const int SAMPLE_MAXIMUM_CHARACTERS = 128;

    private function __construct() {}

    public static function isValid(string $kind): bool
    {
        $length = strlen($kind);

        return $length >= 1 && $length <= self::MAXIMUM_LENGTH && strspn($kind, self::ALLOWED_CHARACTERS) === $length;
    }

    public static function sampleOf(string $kind): string
    {
        $isUtf8Text = preg_match('/^.{0,' . self::SAMPLE_MAXIMUM_CHARACTERS . '}/su', $kind, $firstCharacters) === 1;

        return $isUtf8Text ? $firstCharacters[0] : substr($kind, 0, self::SAMPLE_MAXIMUM_CHARACTERS);
    }
}
