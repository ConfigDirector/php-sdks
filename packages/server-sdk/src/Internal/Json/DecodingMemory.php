<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Json;

final class DecodingMemory
{
    private const int MOST_BYTES_ONE_BYTE_OF_JSON_DECODES_INTO = 100;

    private const int BYTES_OF_A_STRING_BESIDE_ITS_TEXT = 48;

    private const int BYTES_OF_A_MEMBER_OF_AN_ARRAY = 108;

    private const int BYTES_OF_AN_ARRAY_BESIDE_ITS_MEMBERS = 376;

    private const string JSON_STRING_ENDED_BY_ITS_QUOTE_OR_THE_END_OF_THE_TEXT
        = '/"(?:[^"\\\\]++|\\\\(?:.|\z))*+(?:"|\z)/s';

    private function __construct() {}

    public static function isEnoughToHold(int $bytes): bool
    {
        $bytesLeft = self::bytesLeftOrNullWithoutALimit();

        return $bytesLeft === null || $bytes <= $bytesLeft;
    }

    public static function isEnoughFor(string $json): bool
    {
        $bytesLeft = self::bytesLeftOrNullWithoutALimit();
        if ($bytesLeft === null || strlen($json) * self::MOST_BYTES_ONE_BYTE_OF_JSON_DECODES_INTO <= $bytesLeft) {
            return true;
        }

        $jsonWithoutItsStrings = preg_replace(
            self::JSON_STRING_ENDED_BY_ITS_QUOTE_OR_THE_END_OF_THE_TEXT,
            '',
            $json,
            count: $strings,
        );
        $structure = $jsonWithoutItsStrings ?? $json;
        $stringCount = $jsonWithoutItsStrings === null ? intdiv(substr_count($json, '"'), 2) : $strings;
        $bytesTheDecodedValueTakes = strlen($json)
            + self::BYTES_OF_A_STRING_BESIDE_ITS_TEXT * $stringCount
            + self::BYTES_OF_A_MEMBER_OF_AN_ARRAY * substr_count($structure, ',')
            + self::BYTES_OF_AN_ARRAY_BESIDE_ITS_MEMBERS * (substr_count($structure, '{') + substr_count($structure, '['));

        return $bytesTheDecodedValueTakes <= $bytesLeft;
    }

    private static function bytesLeftOrNullWithoutALimit(): ?int
    {
        $bytesAllowed = ini_parse_quantity(ini_get('memory_limit'));

        return $bytesAllowed <= 0 ? null : $bytesAllowed - memory_get_usage();
    }
}
