<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Telemetry;

use ConfigDirector\Internal\Identity\SdkIdentity;
use ConfigDirector\Metadata;

final readonly class TelemetryEndpoint
{
    private const int TIME_LIMIT_MILLISECONDS = 5_000;

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
        $request = curl_init();
        curl_setopt_array($request, [
            CURLOPT_URL => $this->url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $telemetry->reportJson(
                $this->serverSdkKey,
                $this->metaContext,
                $reportedAtEpochMilliseconds,
            ),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Expect:'],
            CURLOPT_USERAGENT => $this->sdkIdentity->userAgent(),
            CURLOPT_TIMEOUT_MS => self::TIME_LIMIT_MILLISECONDS,
            CURLOPT_CONNECTTIMEOUT_MS => self::TIME_LIMIT_MILLISECONDS,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_WRITEFUNCTION => static fn(\CurlHandle $request, string $chunk): int => strlen($chunk),
        ]);

        return curl_exec($request) === false
            ? ReportAnswer::unanswered(curl_error($request))
            : ReportAnswer::withStatus(curl_getinfo($request, CURLINFO_RESPONSE_CODE));
    }
}
