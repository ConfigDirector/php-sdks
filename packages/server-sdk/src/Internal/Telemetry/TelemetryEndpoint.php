<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Telemetry;

use ConfigDirector\Internal\Connection\JsonPostRequest;
use ConfigDirector\Internal\Identity\SdkIdentity;
use ConfigDirector\Internal\Identity\ServerSdkKey;
use ConfigDirector\Metadata;

final readonly class TelemetryEndpoint
{
    /** @var non-empty-string */
    public string $url;

    /** @var array<string, string> */
    public array $metaContext;

    private ServerSdkKey $serverSdkKey;

    public function __construct(
        string $baseUrl,
        #[\SensitiveParameter]
        string $serverSdkKey,
        Metadata $metadata,
        private SdkIdentity $sdkIdentity,
    ) {
        $this->url = rtrim($baseUrl, '/') . '/server/telemetry/v1';
        $this->metaContext = $sdkIdentity->metaContextOfAnApplicationWith($metadata);
        $this->serverSdkKey = new ServerSdkKey($serverSdkKey);
    }

    /**
     * @param int<1, max> $timeLimitMilliseconds
     */
    public function answerToAReportOf(
        CollectedTelemetry $telemetry,
        int $reportedAtEpochMilliseconds,
        int $timeLimitMilliseconds,
    ): ReportAnswer {
        $request = JsonPostRequest::to(
            $this->url,
            $telemetry->reportJson($this->serverSdkKey->text(), $reportedAtEpochMilliseconds),
            $this->sdkIdentity,
        );
        curl_setopt_array($request, [
            CURLOPT_TIMEOUT_MS => $timeLimitMilliseconds,
            CURLOPT_CONNECTTIMEOUT_MS => $timeLimitMilliseconds,
            CURLOPT_WRITEFUNCTION => static fn(\CurlHandle $request, string $chunk): int => strlen($chunk),
        ]);

        return curl_exec($request) === false
            ? ReportAnswer::unanswered(curl_error($request))
            : ReportAnswer::withStatus(curl_getinfo($request, CURLINFO_RESPONSE_CODE));
    }
}
