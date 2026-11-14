<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Polling;

use ConfigDirector\Internal\Connection\JsonPostRequest;
use ConfigDirector\Internal\Connection\RefusalDetail;
use ConfigDirector\Internal\Connection\ServerSdkPayloadText;
use ConfigDirector\Internal\Identity\SdkIdentity;
use ConfigDirector\Metadata;

final readonly class PollingEndpoint
{
    private const int MAXIMUM_RESPONSE_BYTES = 32 * 1024 * 1024;

    /** @var non-empty-string */
    public string $url;

    /** @var array<string, string> */
    private array $metaContext;

    public function __construct(
        string $baseUrl,
        private string $serverSdkKey,
        Metadata $metadata,
        private SdkIdentity $sdkIdentity,
    ) {
        $this->url = rtrim($baseUrl, '/') . '/server/polling/v1';
        $this->metaContext = $sdkIdentity->metaContextOfAnApplicationWith($metadata);
    }

    public function answerToAPoll(
        string $sessionId,
        ?string $lastUpdateTimestamp,
        int $timeLimitMilliseconds,
    ): PollAnswer {
        $responseBody = '';
        $exceedsTheMaximum = false;
        $retryAfterHeader = null;
        $request = JsonPostRequest::to(
            $this->url,
            $this->requestBody($sessionId, $lastUpdateTimestamp),
            $this->sdkIdentity,
        );
        curl_setopt_array($request, [
            CURLOPT_TIMEOUT_MS => $timeLimitMilliseconds,
            CURLOPT_CONNECTTIMEOUT_MS => $timeLimitMilliseconds,
            CURLOPT_HEADERFUNCTION => static function (\CurlHandle $request, string $headerLine) use (
                &$retryAfterHeader,
            ): int {
                $retryAfterHeader = RetryAfterHeader::valueIn($headerLine) ?? $retryAfterHeader;

                return strlen($headerLine);
            },
            CURLOPT_WRITEFUNCTION => static function (\CurlHandle $request, string $chunk) use (
                &$responseBody,
                &$exceedsTheMaximum,
            ): int {
                if (strlen($responseBody) + strlen($chunk) > self::MAXIMUM_RESPONSE_BYTES) {
                    $exceedsTheMaximum = true;

                    return -1;
                }
                $responseBody .= $chunk;

                return strlen($chunk);
            },
        ]);

        $isAnswered = curl_exec($request);
        if ($exceedsTheMaximum) {
            return PollAnswer::unreadable('it is larger than 32 MiB');
        }
        if ($isAnswered === false) {
            return curl_errno($request) === CURLE_OPERATION_TIMEDOUT
                ? PollAnswer::timedOut(curl_error($request))
                : PollAnswer::unanswered(curl_error($request));
        }

        $status = curl_getinfo($request, CURLINFO_RESPONSE_CODE);

        return match ($status) {
            200 => self::answerHolding($responseBody),
            204 => PollAnswer::unchanged(),
            default => PollAnswer::refused(
                $status,
                RefusalDetail::in($responseBody, $this->serverSdkKey),
                $retryAfterHeader,
            ),
        };
    }

    private function requestBody(string $sessionId, ?string $lastUpdateTimestamp): string
    {
        return json_encode(
            array_filter(
                [
                    'serverSdkKey' => $this->serverSdkKey,
                    'sessionId' => $sessionId,
                    'metaContext' => $this->metaContext,
                    'lastUpdateTimestamp' => $lastUpdateTimestamp,
                ],
                static fn(string|array|null $value): bool => $value !== null,
            ),
            JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    private static function answerHolding(string $responseBody): PollAnswer
    {
        $payload = ServerSdkPayloadText::payloadOrWhyItHoldsNone($responseBody);

        return is_string($payload) ? PollAnswer::unreadable($payload) : PollAnswer::payload($payload);
    }
}
