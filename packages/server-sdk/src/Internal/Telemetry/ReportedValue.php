<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Telemetry;

use ConfigDirector\Internal\Evaluation\ScalarText;

final class ReportedValue
{
    private const int MAXIMUM_CHARACTERS_REPORTED_AS_TEXT = 500;

    private const int MOST_LEVELS_OF_ARRAYS_WRITTEN = 512;

    private const int JSON_TEXT_FLAGS = JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_INVALID_UTF8_SUBSTITUTE
        | JSON_PARTIAL_OUTPUT_ON_ERROR;

    private function __construct() {}

    /**
     * @param bool|string|int|float|array<array-key, mixed> $value
     *
     * @return array{value: string}|array{valueId: string}
     */
    public static function of(
        bool|string|int|float|array $value,
        ?string $configType,
        ?string $valueIdSentWithTheValue,
    ): array {
        if ($configType === 'json' || is_array($value)) {
            return ['valueId' => $valueIdSentWithTheValue ?? ValueId::of(self::jsonTextOf($value))];
        }

        $text = ScalarText::of($value) ?? '';

        return self::isShortEnoughToReportAsText($text)
            ? ['value' => $text]
            : ['valueId' => $valueIdSentWithTheValue ?? ValueId::of($text)];
    }

    private static function jsonTextOf(mixed $value, int $levelsOfArraysAroundIt = 0): string
    {
        if ($levelsOfArraysAroundIt > self::MOST_LEVELS_OF_ARRAYS_WRITTEN) {
            throw new \JsonException('The value holds more levels of arrays than can be written as JSON.');
        }
        if (is_float($value)) {
            return is_finite($value) ? ScalarText::of($value) ?? 'null' : 'null';
        }
        if (!is_array($value)) {
            $jsonText = json_encode($value, self::JSON_TEXT_FLAGS);

            return $jsonText === false ? 'null' : $jsonText;
        }

        $isAList = array_is_list($value);
        $memberTexts = [];
        foreach ($value as $name => $member) {
            $memberText = self::jsonTextOf($member, $levelsOfArraysAroundIt + 1);
            $memberTexts[] = $isAList ? $memberText : self::jsonTextOf((string) $name) . ':' . $memberText;
        }

        return $isAList ? '[' . implode(',', $memberTexts) . ']' : '{' . implode(',', $memberTexts) . '}';
    }

    private static function isShortEnoughToReportAsText(string $text): bool
    {
        return match (preg_match('/^.{0,' . self::MAXIMUM_CHARACTERS_REPORTED_AS_TEXT . '}\z/su', $text)) {
            1 => true,
            0 => false,
            false => strlen($text) <= self::MAXIMUM_CHARACTERS_REPORTED_AS_TEXT,
        };
    }
}
