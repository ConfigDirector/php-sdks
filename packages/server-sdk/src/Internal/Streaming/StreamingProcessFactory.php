<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Streaming;

use ConfigDirector\ConfigDirectorValidationException;
use ConfigDirector\ConnectionMode;
use ConfigDirector\ConnectionOptions;
use ConfigDirector\Internal\Client\OptionRules;
use ConfigDirector\Internal\Identity\SdkIdentity;
use ConfigDirector\Internal\LocalStore\LocalStoreOpenedOnFirstUse;
use ConfigDirector\Internal\Telemetry\EventReporter;
use ConfigDirector\Internal\Telemetry\TelemetryEndpoint;
use ConfigDirector\Internal\Time\Clock;
use ConfigDirector\Internal\Time\SystemClock;
use ConfigDirector\Metadata;
use ConfigDirector\TelemetryOptions;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Random\Randomizer;

final class StreamingProcessFactory
{
    private function __construct() {}

    public static function streamingProcessOfTheServerSdk(
        ?string $serverSdkKey,
        ?Metadata $metadata,
        ?ConnectionOptions $connectionOptions,
        ?LoggerInterface $logger,
        ?string $logLevel,
        ?TelemetryOptions $telemetry,
    ): DefaultStreamingProcess {
        $connectionOptions ??= new ConnectionOptions();
        $telemetry ??= new TelemetryOptions();
        $logger ??= OptionRules::errorLogLoggerWritingFrom($logLevel ?? LogLevel::WARNING);
        OptionRules::rejectUnusableTelemetry($telemetry);
        if ($connectionOptions->mode === ConnectionMode::Offline) {
            throw new ConfigDirectorValidationException(
                'A streaming process connects to ConfigDirector, so it cannot be created in offline mode.',
            );
        }
        $providedServerSdkKey = OptionRules::nonBlankServerSdkKey(
            $serverSdkKey,
            'the streaming process cannot be created',
        );
        OptionRules::rejectUnusableConnectionToConfigDirector($connectionOptions);
        $randomizer = new Randomizer();

        return self::streamingProcessTimedBy(
            new SystemClock(),
            $randomizer->nextFloat(...),
            $providedServerSdkKey,
            $metadata ?? new Metadata(),
            $connectionOptions,
            $telemetry,
            $logger,
        );
    }

    /**
     * @param \Closure(): float $randomFraction
     */
    public static function streamingProcessTimedBy(
        Clock $clock,
        \Closure $randomFraction,
        string $serverSdkKey,
        Metadata $metadata,
        ConnectionOptions $connectionOptions,
        TelemetryOptions $telemetry,
        LoggerInterface $logger,
    ): DefaultStreamingProcess {
        $sdkIdentity = SdkIdentity::ofTheServerSdk();
        $localStore = new LocalStoreOpenedOnFirstUse($serverSdkKey, $connectionOptions->storagePath, $logger);
        $localStoreFedByTheStream = new LocalStoreFedByTheStream($localStore, $clock);

        return new DefaultStreamingProcess(
            $localStore,
            new StreamingTransport(
                new StreamEndpoint($connectionOptions->url, $serverSdkKey, $metadata, $sdkIdentity),
                $localStoreFedByTheStream->storePayloadReceived(...),
                $clock,
                $randomFraction,
                $logger,
            ),
            $localStoreFedByTheStream,
            new EventReporter(
                new TelemetryEndpoint($connectionOptions->url, $serverSdkKey, $metadata, $sdkIdentity),
                $telemetry->eventQueueLimit,
                $clock,
                $logger,
            ),
            $telemetry->flushInterval,
            $clock,
            $logger,
        );
    }
}
