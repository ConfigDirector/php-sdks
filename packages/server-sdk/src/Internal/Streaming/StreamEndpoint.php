<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Streaming;

use ConfigDirector\Internal\Connection\JsonPostRequest;
use ConfigDirector\Internal\Connection\RefusalDetail;
use ConfigDirector\Internal\Identity\SdkIdentity;
use ConfigDirector\Metadata;

final readonly class StreamEndpoint
{
    private const int MILLISECONDS_FOR_A_HEARTBEAT = 10_000;

    /** @var non-empty-string */
    private string $streamUrl;

    /** @var non-empty-string */
    private string $heartbeatUrl;

    /** @var array<string, string> */
    private array $metaContext;

    public function __construct(
        string $baseUrl,
        private string $serverSdkKey,
        Metadata $metadata,
        private SdkIdentity $sdkIdentity,
    ) {
        $this->streamUrl = rtrim($baseUrl, '/') . '/server/sse/v1';
        $this->heartbeatUrl = rtrim($baseUrl, '/') . '/server/heartbeat/v1';
        $this->metaContext = $sdkIdentity->metaContextOfAnApplicationWith($metadata);
    }

    public function requestOpeningAStream(string $sessionId, string $lastEventId): \CurlHandle
    {
        $request = JsonPostRequest::to(
            $this->streamUrl,
            self::jsonOf(
                ['serverSdkKey' => $this->serverSdkKey, 'sessionId' => $sessionId, 'metaContext' => $this->metaContext],
            ),
            $this->sdkIdentity,
            ['Accept: text/event-stream', ...($lastEventId === '' ? [] : ["Last-Event-ID: {$lastEventId}"])],
        );
        curl_setopt_array($request, [CURLOPT_FRESH_CONNECT => true, CURLOPT_FORBID_REUSE => true]);

        return $request;
    }

    public function requestSendingAHeartbeat(string $sessionId): \CurlHandle
    {
        $request = JsonPostRequest::to(
            $this->heartbeatUrl,
            self::jsonOf(['serverSdkKey' => $this->serverSdkKey, 'sessionId' => $sessionId]),
            $this->sdkIdentity,
        );
        curl_setopt_array($request, [
            CURLOPT_TIMEOUT_MS => self::MILLISECONDS_FOR_A_HEARTBEAT,
            CURLOPT_CONNECTTIMEOUT_MS => self::MILLISECONDS_FOR_A_HEARTBEAT,
            CURLOPT_WRITEFUNCTION => static fn(\CurlHandle $request, string $chunk): int => strlen($chunk),
        ]);

        return $request;
    }

    public function refusalDetailIn(string $responseBody): string
    {
        return RefusalDetail::in($responseBody, $this->serverSdkKey);
    }

    /**
     * @param array<string, string|array<string, string>> $requestBody
     */
    private static function jsonOf(array $requestBody): string
    {
        return json_encode(
            $requestBody,
            JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }
}
