<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Client;

use ConfigDirector\ClientHooks;
use ConfigDirector\ConfigDirectorClient;
use ConfigDirector\ConfigDirectorConnectionException;
use ConfigDirector\ConfigDirectorValidationException;
use ConfigDirector\ConnectionMode;
use ConfigDirector\ConnectionOptions;
use ConfigDirector\Internal\Connection\Connection;
use ConfigDirector\Internal\Connection\LocalStoreConnection;
use ConfigDirector\Internal\Connection\OfflineConnection;
use ConfigDirector\Internal\Identity\SdkIdentity;
use ConfigDirector\Internal\LocalStore\LocalStore;
use ConfigDirector\Internal\Logging\ErrorLogLogger;
use ConfigDirector\Internal\Polling\PollingEndpoint;
use ConfigDirector\Internal\Polling\PollingInterval;
use ConfigDirector\Internal\Polling\PollingTransport;
use ConfigDirector\Internal\Time\SystemClock;
use ConfigDirector\Metadata;
use ConfigDirector\TelemetryOptions;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

final class ClientFactory
{
    private const int MINIMUM_EVENT_QUEUE_LIMIT = 100;

    private const int MAXIMUM_EVENT_QUEUE_LIMIT = 100_000;

    private function __construct() {}

    public static function clientIdentifiedAs(
        SdkIdentity $sdkIdentity,
        ?string $serverSdkKey,
        ?Metadata $metadata,
        ?ConnectionOptions $connectionOptions,
        ?LoggerInterface $logger,
        ?string $logLevel,
        ?TelemetryOptions $telemetry,
        ?ClientHooks $hooks,
    ): ConfigDirectorClient {
        $metadata ??= new Metadata();
        $connectionOptions ??= new ConnectionOptions();
        $telemetry ??= new TelemetryOptions();
        $logger ??= self::errorLogLoggerWritingFrom($logLevel ?? LogLevel::WARNING);
        self::rejectUnusableTimeout($connectionOptions);
        $serverSdkKeyToPollWith = match ($connectionOptions->mode) {
            ConnectionMode::Offline => null,
            ConnectionMode::Polling => self::serverSdkKeyOfAUsableConnection($serverSdkKey, $connectionOptions),
        };
        self::rejectUnusableTelemetry($telemetry);
        $eventHandlers = self::eventHandlersRegisteredFrom($hooks ?? new ClientHooks(), $logger);

        return new DefaultConfigDirectorClient(
            $serverSdkKeyToPollWith === null
                ? new OfflineConnection()
                : self::connectionReadingTheLocalStore(
                    $sdkIdentity,
                    $serverSdkKeyToPollWith,
                    $metadata,
                    $connectionOptions,
                    $telemetry,
                    $logger,
                    $eventHandlers,
                ),
            $metadata,
            $connectionOptions->timeout,
            $logger,
            $eventHandlers,
        );
    }

    private static function connectionReadingTheLocalStore(
        SdkIdentity $sdkIdentity,
        string $serverSdkKey,
        Metadata $metadata,
        ConnectionOptions $connectionOptions,
        TelemetryOptions $telemetry,
        LoggerInterface $logger,
        EventHandlers $eventHandlers,
    ): Connection {
        $pollingInterval = PollingInterval::ofConfiguredSeconds($connectionOptions->pollingInterval);
        if ($pollingInterval->isRaisedToTheMinimum) {
            $logger->warning(
                '[ConfigDirectorClient] pollingInterval of {configuredSeconds} seconds is below the minimum of '
                . '{minimumSeconds} seconds. Using {minimumSeconds} seconds.',
                [
                    'configuredSeconds' => $connectionOptions->pollingInterval,
                    'minimumSeconds' => $pollingInterval->seconds,
                ],
            );
        }

        return new LocalStoreConnection(
            static fn(\Closure $reportPayloadReceivedByAPoll): PollingTransport => new PollingTransport(
                LocalStore::ofServerSdkKey($serverSdkKey, $connectionOptions->storagePath, $logger),
                new PollingEndpoint($connectionOptions->url, $serverSdkKey, $metadata, $sdkIdentity),
                $pollingInterval,
                $connectionOptions->timeout,
                $telemetry->flushInterval,
                new SystemClock(),
                $logger,
                static function (ConfigDirectorConnectionException $error) use ($eventHandlers): void {
                    $eventHandlers->deliverConnectionError($error);
                },
                $reportPayloadReceivedByAPoll,
            ),
            $logger,
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

    private static function rejectUnusableTimeout(ConnectionOptions $connectionOptions): void
    {
        if ($connectionOptions->timeout <= 0) {
            throw new ConfigDirectorValidationException(
                "Invalid timeout '{$connectionOptions->timeout}'. The timeout must be a positive number of "
                . 'milliseconds.',
            );
        }
    }

    private static function serverSdkKeyOfAUsableConnection(
        ?string $serverSdkKey,
        ConnectionOptions $connectionOptions,
    ): string {
        if ($serverSdkKey === null || trim($serverSdkKey) === '') {
            throw new ConfigDirectorValidationException(
                'No server SDK key was provided, the client cannot be instantiated without a valid server SDK key',
            );
        }
        self::rejectUnusableBaseUrl($connectionOptions->url);
        self::rejectUnusableStoragePath($connectionOptions->storagePath);

        return $serverSdkKey;
    }

    private static function rejectUnusableBaseUrl(string $baseUrl): void
    {
        $urlParts = parse_url($baseUrl);
        $scheme = strtolower($urlParts['scheme'] ?? '');
        $isHttpUrl = in_array($scheme, ['http', 'https'], true) && ($urlParts['host'] ?? '') !== '';
        $holdsSpacesOrControlCharacters = preg_match('/[\x00-\x20\x7F]/', $baseUrl) === 1;
        if (!$isHttpUrl || $holdsSpacesOrControlCharacters) {
            throw new ConfigDirectorValidationException(
                "Invalid base URL '{$baseUrl}'. The base URL must be an http or https URL.",
            );
        }
    }

    private static function rejectUnusableStoragePath(?string $storagePath): void
    {
        if ($storagePath === null) {
            return;
        }

        $isAbsolute = PHP_OS_FAMILY === 'Windows'
            ? preg_match('/^(?:[A-Za-z]:[\\\\\/]|\\\\\\\\)/', $storagePath) === 1
            : str_starts_with($storagePath, '/');
        if (!$isAbsolute) {
            throw new ConfigDirectorValidationException(
                "Invalid storage path '{$storagePath}'. The storage path must be an absolute path.",
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
}
