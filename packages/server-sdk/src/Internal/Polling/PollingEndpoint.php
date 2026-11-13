<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Polling;

use ConfigDirector\Internal\Identity\SdkIdentity;
use ConfigDirector\Internal\LocalStore\StoredPayload;
use ConfigDirector\Metadata;

final readonly class PollingEndpoint
{
    private const int MAXIMUM_RESPONSE_BYTES = 32 * 1024 * 1024;

    private const int MAXIMUM_REFUSAL_DETAIL_BYTES = 1_024;

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
        $request = curl_init();
        curl_setopt_array($request, [
            CURLOPT_URL => $this->url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $this->requestBody($sessionId, $lastUpdateTimestamp),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_USERAGENT => $this->sdkIdentity->userAgent(),
            CURLOPT_TIMEOUT_MS => $timeLimitMilliseconds,
            CURLOPT_CONNECTTIMEOUT_MS => $timeLimitMilliseconds,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => static function (\CurlHandle $request, string $headerLine) use (
                &$retryAfterHeader,
            ): int {
                [$name, $value] = [...explode(':', $headerLine, 2), ''];
                if (strcasecmp(trim($name), 'Retry-After') === 0) {
                    $retryAfterHeader = trim($value);
                }

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
            default => PollAnswer::refused($status, $this->refusalDetailIn($responseBody), $retryAfterHeader),
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
        try {
            $payload = json_decode($responseBody, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            return PollAnswer::unreadable($exception->getMessage());
        }
        $isAJsonObject = is_array($payload) && ($payload === [] || !array_is_list($payload));
        if (!$isAJsonObject) {
            return PollAnswer::unreadable('it is not a JSON object');
        }

        return StoredPayload::holdsAConfigsObject($payload)
            ? PollAnswer::payload($payload)
            : PollAnswer::unreadable('it carries no configs object');
    }

    private function refusalDetailIn(string $responseBody): string
    {
        $detail = str_replace($this->serverSdkKey, '[the server SDK key]', $responseBody);

        return strlen($detail) > self::MAXIMUM_REFUSAL_DETAIL_BYTES
            ? trim(substr($detail, 0, self::MAXIMUM_REFUSAL_DETAIL_BYTES)) . '…'
            : trim($detail);
    }
}
