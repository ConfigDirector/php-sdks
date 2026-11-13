<?php

declare(strict_types=1);

namespace ConfigDirector;

use ConfigDirector\Internal\Client\ClientEventName;
use ConfigDirector\Internal\Client\DefaultConfigDirectorClient;
use ConfigDirector\Internal\Client\EventHandlers;
use ConfigDirector\Internal\Connection\HardCodedConnection;
use ConfigDirector\Internal\Logging\ErrorLogLogger;
use ConfigDirector\Internal\Polling\PollingInterval;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * The entry point of the ConfigDirector server SDK.
 */
final class ConfigDirector
{
    private const int MINIMUM_EVENT_QUEUE_LIMIT = 100;

    private const int MAXIMUM_EVENT_QUEUE_LIMIT = 100_000;

    private function __construct() {}

    /**
     * Creates a ConfigDirector client. It returns a client that is not initialized and makes no
     * network call: call {@see ConfigDirectorClient::initialize()} before the first read.
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
     * @throws ConfigDirectorValidationException When the server SDK key is missing or blank outside
     *     offline mode, when `$connection->url` is not an http or https URL, when
     *     `$connection->timeout` or a `$telemetry` setting is out of range, or when `$logLevel` is
     *     not a PSR-3 level and no `$logger` is given.
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
        $connection ??= new ConnectionOptions();
        $logger ??= self::errorLogLoggerWritingFrom($logLevel ?? LogLevel::WARNING);
        self::rejectUnusableServerSdkKeyOrConnection($serverSdkKey, $connection);
        self::rejectUnusableTelemetry($telemetry ?? new TelemetryOptions());
        $pollingInterval = PollingInterval::ofConfiguredSeconds($connection->pollingInterval);
        self::warnOfAPollingIntervalRaisedToTheMinimum($pollingInterval, $connection, $logger);

        return new DefaultConfigDirectorClient(
            new HardCodedConnection(),
            $metadata ?? new Metadata(),
            $connection->timeout,
            $logger,
            self::eventHandlersRegisteredFrom($hooks ?? new ClientHooks(), $logger),
        );
    }

    private static function eventHandlersRegisteredFrom(ClientHooks $hooks, LoggerInterface $logger): EventHandlers
    {
        $eventHandlers = new EventHandlers($logger);
        foreach (ClientEventName::cases() as $eventName) {
            foreach ($eventName->hooksIn($hooks) as $hook) {
                if (is_callable($hook)) {
                    $eventHandlers->add($eventName, $hook);
                } else {
                    $logger->warning(
                        "[ConfigDirectorClient] A '{eventName}' hook of type {hookType} was ignored because it is not "
                        . 'callable.',
                        ['eventName' => $eventName->value, 'hookType' => get_debug_type($hook)],
                    );
                }
            }
        }

        return $eventHandlers;
    }

    private static function errorLogLoggerWritingFrom(string $logLevel): LoggerInterface
    {
        return ErrorLogLogger::writingFrom($logLevel) ?? throw new ConfigDirectorValidationException(
            "Invalid log level '{$logLevel}'. The log level must be one of the levels of Psr\\Log\\LogLevel, "
            . "such as 'debug' or 'warning'.",
        );
    }

    private static function rejectUnusableServerSdkKeyOrConnection(
        ?string $serverSdkKey,
        ConnectionOptions $connection,
    ): void {
        if ($connection->timeout <= 0) {
            throw new ConfigDirectorValidationException(
                "Invalid timeout '{$connection->timeout}'. The timeout must be a positive number of milliseconds.",
            );
        }
        if ($connection->mode === ConnectionMode::Offline) {
            return;
        }

        if ($serverSdkKey === null || trim($serverSdkKey) === '') {
            throw new ConfigDirectorValidationException(
                'No server SDK key was provided, the client cannot be instantiated without a valid server SDK key',
            );
        }
        $urlParts = parse_url($connection->url);
        $scheme = strtolower($urlParts['scheme'] ?? '');
        $isHttpUrl = in_array($scheme, ['http', 'https'], true) && ($urlParts['host'] ?? '') !== '';
        $holdsSpacesOrControlCharacters = preg_match('/[\x00-\x20\x7F]/', $connection->url) === 1;
        if (!$isHttpUrl || $holdsSpacesOrControlCharacters) {
            throw new ConfigDirectorValidationException(
                "Invalid base URL '{$connection->url}'. The base URL must be an http or https URL.",
            );
        }
    }

    private static function rejectUnusableTelemetry(TelemetryOptions $telemetry): void
    {
        $eventQueueLimitIsInRange = $telemetry->eventQueueLimit >= self::MINIMUM_EVENT_QUEUE_LIMIT
            && $telemetry->eventQueueLimit <= self::MAXIMUM_EVENT_QUEUE_LIMIT;
        if (!$eventQueueLimitIsInRange) {
            throw new ConfigDirectorValidationException(sprintf(
                "Invalid telemetry event queue limit '%d'. It must be between %d and %d.",
                $telemetry->eventQueueLimit,
                self::MINIMUM_EVENT_QUEUE_LIMIT,
                self::MAXIMUM_EVENT_QUEUE_LIMIT,
            ));
        }
        if ($telemetry->flushInterval <= 0) {
            throw new ConfigDirectorValidationException(
                "Invalid telemetry flush interval '{$telemetry->flushInterval}'. "
                . 'It must be a positive number of milliseconds.',
            );
        }
    }

    private static function warnOfAPollingIntervalRaisedToTheMinimum(
        PollingInterval $pollingInterval,
        ConnectionOptions $connection,
        LoggerInterface $logger,
    ): void {
        if ($connection->mode !== ConnectionMode::Polling || !$pollingInterval->isRaisedToTheMinimum) {
            return;
        }

        $logger->warning(
            '[ConfigDirectorClient] pollingInterval of {configuredSeconds} seconds is below the minimum of '
            . '{minimumSeconds} seconds. Using {minimumSeconds} seconds.',
            ['configuredSeconds' => $connection->pollingInterval, 'minimumSeconds' => $pollingInterval->seconds],
        );
    }
}
