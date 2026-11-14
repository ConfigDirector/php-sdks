<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Connection;

final class RefusalDetail
{
    private const int MAXIMUM_BYTES = 1_024;

    private function __construct() {}

    public static function in(string $responseBody, #[\SensitiveParameter] string $serverSdkKey): string
    {
        $detail = str_replace(self::writtenFormsOf($serverSdkKey), '[the server SDK key]', $responseBody);

        return strlen($detail) > self::MAXIMUM_BYTES
            ? trim(substr($detail, 0, self::MAXIMUM_BYTES)) . '…'
            : trim($detail);
    }

    /**
     * @return list<string>
     */
    private static function writtenFormsOf(#[\SensitiveParameter] string $serverSdkKey): array
    {
        $writtenForms = [$serverSdkKey, trim($serverSdkKey)];
        foreach ([JSON_INVALID_UTF8_SUBSTITUTE, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES] as $jsonFlags) {
            $jsonText = json_encode(trim($serverSdkKey), $jsonFlags);
            if ($jsonText !== false) {
                $writtenForms[] = substr($jsonText, 1, -1);
            }
        }

        return array_values(array_filter($writtenForms, static fn(string $form): bool => $form !== ''));
    }
}
