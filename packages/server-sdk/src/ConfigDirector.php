<?php

declare(strict_types=1);

namespace ConfigDirector;

use ConfigDirector\Internal\Client\ClientFactory;
use ConfigDirector\Internal\Identity\SdkIdentity;
use ConfigDirector\Internal\Streaming\StreamingProcessFactory;
use Psr\Log\LoggerInterface;

/**
 * The entry point of the ConfigDirector server SDK.
 */
final class ConfigDirector
{
    private function __construct() {}

    /**
     * Creates a ConfigDirector client. It returns a client that is not initialized, and makes no
     * network call and touches no file: call {@see ConfigDirectorClient::initialize()} before the
     * first read.
     *
     * ```
     * $client = ConfigDirector::createClient(
     *     serverSdkKey: 'YOUR-SERVER-SDK-KEY',
     *     metadata: new Metadata(appName: 'my-awesome-app', appVersion: '1.0.0'),
     * );
     * $client->initialize();
     * ```
     *
     * @param string|null $serverSdkKey The ConfigDirector server SDK key. This is a secret value,
     *     do not commit it in your source code. It can be left out in offline mode only.
     * @param Metadata|null $metadata Metadata about your application. Including `appName` and
     *     `appVersion` is recommended, so that you can use them in targeting rules.
     * @param ConnectionOptions|null $connection Connection options.
     * @param LoggerInterface|null $logger A PSR-3 logger. Without one the SDK writes warnings and
     *     errors through `error_log`.
     * @param string|null $logLevel The lowest level the SDK writes when no `$logger` is given, as
     *     a PSR-3 level such as `Psr\Log\LogLevel::DEBUG`. Defaults to `warning`. It has no effect
     *     when a `$logger` is given.
     * @param TelemetryOptions|null $telemetry Telemetry tuning.
     * @param ClientHooks|null $hooks Handlers for the events the client emits.
     *
     * @throws ConfigDirectorValidationException Outside offline mode, when the server SDK key is
     *     missing or blank, `$connection->file` or `$connection->values` is given,
     *     `$connection->url` is not an http or https URL, `$connection->storagePath` is not an
     *     absolute path, or `$connection->timeout` is zero or less. In offline mode, when both
     *     `$connection->file` and `$connection->values` are given, or `$connection->values` holds
     *     a value the SDK cannot serve, such as `null`, a float that is not finite or an object
     *     other than `stdClass`, or a blank config key. In every mode, when a `$telemetry` setting
     *     is out of range, or when `$logLevel` is not a PSR-3 level and no `$logger` is given.
     */
    public static function createClient(
        ?string $serverSdkKey = null,
        ?Metadata $metadata = null,
        ?ConnectionOptions $connection = null,
        ?LoggerInterface $logger = null,
        ?string $logLevel = null,
        ?TelemetryOptions $telemetry = null,
        ?ClientHooks $hooks = null,
    ): ConfigDirectorClient {
        return ClientFactory::clientIdentifiedAs(
            SdkIdentity::ofTheServerSdk(),
            $serverSdkKey,
            $metadata,
            $connection,
            $logger,
            $logLevel,
            $telemetry,
            $hooks,
        );
    }

    /**
     * Creates the streaming process of a host, which makes a change to the targeting rules reach
     * the application's requests in about a second, where they otherwise wait for the next poll.
     * Call {@see StreamingProcess::run()} from a long-running command, one per host, as the
     * system user that runs the application, and with the server SDK key and `storagePath` the
     * application's clients use. The command `vendor/bin/configdirector-stream` does the same
     * from environment variables.
     *
     * ```
     * ConfigDirector::createStreamingProcess(
     *     serverSdkKey: 'YOUR-SERVER-SDK-KEY',
     *     metadata: new Metadata(appName: 'my-awesome-app', appVersion: '1.0.0'),
     * )->run();
     * ```
     *
     * It makes no network call and touches no file until `run()` is called.
     *
     * @param string|null $serverSdkKey The ConfigDirector server SDK key. This is a secret value,
     *     do not commit it in your source code.
     * @param Metadata|null $metadata Metadata about your application. The streaming connection and
     *     the telemetry the streaming process sends are shown in ConfigDirector under this
     *     `appName` and `appVersion`, so give it the ones the application's clients have.
     * @param ConnectionOptions|null $connection Connection options. `url` and `storagePath` apply.
     *     `pollingInterval` and `timeout` change nothing about a streaming process, though a
     *     `timeout` of zero or less is rejected as `createClient` rejects it.
     * @param LoggerInterface|null $logger A PSR-3 logger. Without one the streaming process writes
     *     warnings and errors through `error_log`.
     * @param string|null $logLevel The lowest level written when no `$logger` is given, as a PSR-3
     *     level such as `Psr\Log\LogLevel::INFO`. Defaults to `warning`. It has no effect when a
     *     `$logger` is given.
     * @param TelemetryOptions|null $telemetry Telemetry tuning. The streaming process sends the
     *     telemetry of the local store once per `flushInterval`.
     *
     * @throws ConfigDirectorValidationException When the server SDK key is missing or blank, the
     *     connection mode is offline, `$connection->file` or `$connection->values` is given,
     *     `$connection->url` is not an http or https URL, `$connection->storagePath` is not an
     *     absolute path, `$connection->timeout` is zero or less, a `$telemetry` setting is out of
     *     range, or `$logLevel` is not a PSR-3 level and no `$logger` is given.
     */
    public static function createStreamingProcess(
        ?string $serverSdkKey = null,
        ?Metadata $metadata = null,
        ?ConnectionOptions $connection = null,
        ?LoggerInterface $logger = null,
        ?string $logLevel = null,
        ?TelemetryOptions $telemetry = null,
    ): StreamingProcess {
        return StreamingProcessFactory::streamingProcessOfTheServerSdk(
            $serverSdkKey,
            $metadata,
            $connection,
            $logger,
            $logLevel,
            $telemetry,
        );
    }
}
