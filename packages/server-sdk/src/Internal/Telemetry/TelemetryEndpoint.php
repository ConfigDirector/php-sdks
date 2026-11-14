<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Telemetry;

use ConfigDirector\Internal\Connection\JsonPostRequest;
use ConfigDirector\Internal\Identity\SdkIdentity;
use ConfigDirector\Metadata;

final readonly class TelemetryEndpoint
{
    private const int TIME_LIMIT_MILLISECONDS = 5_000;

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
        $this->url = rtrim($baseUrl, '/') . '/server/telemetry/v1';
        $this->metaContext = $sdkIdentity->metaContextOfAnApplicationWith($metadata);
    }

    public function answerToAReportOf(CollectedTelemetry $telemetry, int $reportedAtEpochMilliseconds): ReportAnswer
    {
        $request = JsonPostRequest::to(
            $this->url,
            $telemetry->reportJson($this->serverSdkKey, $this->metaContext, $reportedAtEpochMilliseconds),
            $this->sdkIdentity,
        );
        curl_setopt_array($request, [
            CURLOPT_TIMEOUT_MS => self::TIME_LIMIT_MILLISECONDS,
            CURLOPT_CONNECTTIMEOUT_MS => self::TIME_LIMIT_MILLISECONDS,
            CURLOPT_WRITEFUNCTION => static fn(\CurlHandle $request, string $chunk): int => strlen($chunk),
        ]);

        return curl_exec($request) === false
            ? ReportAnswer::unanswered(curl_error($request))
            : ReportAnswer::withStatus(curl_getinfo($request, CURLINFO_RESPONSE_CODE));
    }
}
