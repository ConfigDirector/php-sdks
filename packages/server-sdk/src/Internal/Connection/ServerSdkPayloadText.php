<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Connection;

use ConfigDirector\Internal\Json\DecodingMemory;
use ConfigDirector\Internal\LocalStore\StoredPayload;

final class ServerSdkPayloadText
{
    private function __construct() {}

    /**
     * @return array<array-key, mixed>|string
     */
    public static function payloadOrWhyItHoldsNone(string $json): array|string
    {
        if (!DecodingMemory::isEnoughFor($json)) {
            return 'it needs more memory than the memory_limit of this process leaves';
        }

        try {
            $payload = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            return $exception->getMessage();
        }

        $isAJsonObject = is_array($payload) && ($payload === [] || !array_is_list($payload));
        if (!$isAJsonObject) {
            return 'it is not a JSON object';
        }

        return StoredPayload::holdsAConfigsObject($payload) ? $payload : 'it carries no configs object';
    }
}
