<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Connection;

use ConfigDirector\Internal\Identity\SdkIdentity;

final class JsonPostRequest
{
    private function __construct() {}

    /**
     * @param non-empty-string $url
     * @param list<string> $furtherHeaders
     */
    public static function to(
        string $url,
        string $jsonBody,
        SdkIdentity $sdkIdentity,
        array $furtherHeaders = [],
    ): \CurlHandle {
        $request = curl_init();
        curl_setopt_array($request, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonBody,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Expect:', ...$furtherHeaders],
            CURLOPT_USERAGENT => $sdkIdentity->userAgent(),
            CURLOPT_NOSIGNAL => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        return $request;
    }
}
