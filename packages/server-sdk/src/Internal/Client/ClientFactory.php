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
use ConfigDirector\Internal\Connection\InMemoryConnection;
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
use ConfigDirector\Testing\TestClient;
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
        self::rejectUnusableTelemetry($telemetry);
        $eventHandlers = new EventHandlers($logger);
        $connection = match ($connectionOptions->mode) {
            ConnectionMode::Offline => self::offlineConnectionServingTheValuesIn($connectionOptions),
            ConnectionMode::Polling => self::connectionReadingTheLocalStore(
                $sdkIdentity,
                self::serverSdkKeyOfAUsableConnection($serverSdkKey, $connectionOptions),
                $metadata,
                $connectionOptions,
                $telemetry,
                $logger,
                $eventHandlers,
            ),
        };
        self::registerTheHooksIn($eventHandlers, $hooks ?? new ClientHooks(), $logger);

        return new DefaultConfigDirectorClient(
            $connection,
            $metadata,
            $connectionOptions->timeout,
            $logger,
            $eventHandlers,
        );
    }

    /**
     * @param array<array-key, mixed>|null $values
     */
    public static function testClientServing(?array $values, ?int $timeout, ?LoggerInterface $logger): TestClient
    {
        $logger ??= self::errorLogLoggerWritingFrom(LogLevel::WARNING);
        $timeout ??= (new ConnectionOptions())->timeout;
        self::rejectUnusableTimeout($timeout);
        $eventHandlers = new EventHandlers($logger);
        $connection = InMemoryConnection::servingTheValues(
            $values ?? [],
            $timeout,
            $logger,
            $eventHandlers->deliverConnectionError(...),
        );
        $client = new DefaultConfigDirectorClient($connection, new Metadata(), $timeout, $logger, $eventHandlers);
        $connection->deliverPayloadsTo($client->takeIn(...));

        return new TestClient($client, $connection);
    }

    private static function offlineConnectionServingTheValuesIn(ConnectionOptions $connectionOptions): Connection
    {
        if ($connectionOptions->file !== null && $connectionOptions->values !== null) {
            throw new ConfigDirectorValidationException(
                "An offline connection takes either 'file' or 'values', not both.",
            );
        }

        return $connectionOptions->file === null
            ? OfflineConnection::servingTheValues($connectionOptions->values ?? [])
            : OfflineConnection::servingTheValuesOfTheFile($connectionOptions->file);
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

    private static function registerTheHooksIn(
        EventHandlers $eventHandlers,
        ClientHooks $hooks,
        LoggerInterface $logger,
    ): void {
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
    }

    private static function errorLogLoggerWritingFrom(string $logLevel): LoggerInterface
    {
        return ErrorLogLogger::writingFrom($logLevel) ?? throw new ConfigDirectorValidationException(
            "Invalid log level '{$logLevel}'. The log level must be one of the levels of Psr\\Log\\LogLevel, "
            . "such as 'debug' or 'warning'.",
        );
    }

    private static function rejectUnusableTimeout(int $timeoutMilliseconds): void
    {
        if ($timeoutMilliseconds <= 0) {
            throw new ConfigDirectorValidationException(
                "Invalid timeout '{$timeoutMilliseconds}'. The timeout must be a positive number of milliseconds.",
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
        if ($connectionOptions->file !== null || $connectionOptions->values !== null) {
            throw new ConfigDirectorValidationException(
                "The connection options 'file' and 'values' apply only when the connection mode is 'offline', but "
                . "the mode is '{$connectionOptions->mode->value}'.",
            );
        }
        self::rejectUnusableBaseUrl($connectionOptions->url);
        self::rejectUnusableStoragePath($connectionOptions->storagePath);
        self::rejectUnusableTimeout($connectionOptions->timeout);

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
