<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Connection;

final class RefusalDetail
{
    private const int MAXIMUM_BYTES = 1_024;

    private function __construct() {}

    public static function in(string $responseBody, string $serverSdkKey): string
    {
        $detail = str_replace($serverSdkKey, '[the server SDK key]', $responseBody);

        return strlen($detail) > self::MAXIMUM_BYTES
            ? trim(substr($detail, 0, self::MAXIMUM_BYTES)) . '…'
            : trim($detail);
    }
}
