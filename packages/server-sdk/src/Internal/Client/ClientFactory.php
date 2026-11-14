<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Client;

use ConfigDirector\ClientHooks;
use ConfigDirector\ConfigDirectorClient;
use ConfigDirector\ConfigDirectorValidationException;
use ConfigDirector\ConnectionMode;
use ConfigDirector\ConnectionOptions;
use ConfigDirector\Internal\Connection\Connection;
use ConfigDirector\Internal\Connection\InMemoryConnection;
use ConfigDirector\Internal\Connection\LocalStoreConnection;
use ConfigDirector\Internal\Connection\OfflineConnection;
use ConfigDirector\Internal\Identity\SdkIdentity;
use ConfigDirector\Internal\LocalStore\LocalStoreOpenedOnFirstUse;
use ConfigDirector\Internal\Polling\PollingEndpoint;
use ConfigDirector\Internal\Polling\PollingInterval;
use ConfigDirector\Internal\Polling\PollingTransport;
use ConfigDirector\Internal\Telemetry\ClientTelemetry;
use ConfigDirector\Internal\Telemetry\DiscardedTelemetry;
use ConfigDirector\Internal\Telemetry\EventReporter;
use ConfigDirector\Internal\Telemetry\LocalStoreTelemetry;
use ConfigDirector\Internal\Telemetry\TelemetryEndpoint;
use ConfigDirector\Internal\Telemetry\TrackedEventRecordingTelemetry;
use ConfigDirector\Internal\Time\ClientLifetime;
use ConfigDirector\Internal\Time\SystemClock;
use ConfigDirector\Metadata;
use ConfigDirector\TelemetryOptions;
use ConfigDirector\Testing\TestClient;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

final class ClientFactory
{
    private function __construct() {}

    public static function clientIdentifiedAs(
        SdkIdentity $sdkIdentity,
        #[\SensitiveParameter]
        ?string $serverSdkKey,
        ?Metadata $metadata,
        ?ConnectionOptions $connectionOptions,
        ?LoggerInterface $logger,
        ?string $logLevel,
        ?TelemetryOptions $telemetryOptions,
        ?ClientHooks $hooks,
    ): ConfigDirectorClient {
        $metadata ??= new Metadata();
        $connectionOptions ??= new ConnectionOptions();
        $telemetryOptions ??= new TelemetryOptions();
        $logger ??= OptionRules::errorLogLoggerWritingFrom($logLevel ?? LogLevel::WARNING);
        OptionRules::rejectUnusableTelemetry($telemetryOptions);
        $eventHandlers = new EventHandlers($logger);
        [$connection, $clientTelemetry] = match ($connectionOptions->mode) {
            ConnectionMode::Offline => [
                self::offlineConnectionServingTheValuesIn($connectionOptions),
                new DiscardedTelemetry(),
            ],
            ConnectionMode::Polling => self::connectionAndTelemetryOfTheLocalStore(
                $sdkIdentity,
                self::serverSdkKeyOfAUsableConnection($serverSdkKey, $connectionOptions),
                $metadata,
                $connectionOptions,
                $telemetryOptions,
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
            $clientTelemetry,
        );
    }

    /**
     * @param array<array-key, mixed>|null $values
     */
    public static function testClientServing(?array $values, ?int $timeout, ?LoggerInterface $logger): TestClient
    {
        $logger ??= OptionRules::errorLogLoggerWritingFrom(LogLevel::WARNING);
        $timeout ??= (new ConnectionOptions())->timeout;
        OptionRules::rejectUnusableTimeout($timeout);
        $eventHandlers = new EventHandlers($logger);
        $clientTelemetry = new TrackedEventRecordingTelemetry($logger);
        $connection = InMemoryConnection::servingTheValues(
            $values ?? [],
            $timeout,
            $logger,
            $eventHandlers->deliverConnectionError(...),
        );
        $client = new DefaultConfigDirectorClient(
            $connection,
            new Metadata(),
            $timeout,
            $logger,
            $eventHandlers,
            $clientTelemetry,
        );
        $connection->deliverPayloadsTo($client->takeIn(...));

        return new TestClient($client, $connection, $clientTelemetry);
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

    /**
     * @return array{Connection, ClientTelemetry}
     */
    private static function connectionAndTelemetryOfTheLocalStore(
        SdkIdentity $sdkIdentity,
        #[\SensitiveParameter]
        string $serverSdkKey,
        Metadata $metadata,
        ConnectionOptions $connectionOptions,
        TelemetryOptions $telemetryOptions,
        LoggerInterface $logger,
        EventHandlers $eventHandlers,
    ): array {
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

        $clock = new SystemClock();
        $clientLifetime = new ClientLifetime($clock, $telemetryOptions->flushInterval);
        $localStore = new LocalStoreOpenedOnFirstUse($serverSdkKey, $connectionOptions->storagePath, $logger);

        return [
            new LocalStoreConnection(
                static fn(\Closure $reportPayloadReceivedByAPoll): PollingTransport => new PollingTransport(
                    $localStore->store(),
                    new PollingEndpoint($connectionOptions->url, $serverSdkKey, $metadata, $sdkIdentity),
                    $pollingInterval,
                    $connectionOptions->timeout,
                    $clientLifetime,
                    $clock,
                    $logger,
                    $eventHandlers->deliverConnectionError(...),
                    $reportPayloadReceivedByAPoll,
                ),
                $logger,
            ),
            new LocalStoreTelemetry(
                $localStore,
                new EventReporter(
                    new TelemetryEndpoint($connectionOptions->url, $serverSdkKey, $metadata, $sdkIdentity),
                    $telemetryOptions->eventQueueLimit,
                    $clock,
                    $logger,
                ),
                $telemetryOptions->eventQueueLimit,
                $telemetryOptions->flushInterval,
                $clientLifetime,
                $clock,
                $logger,
            ),
        ];
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

    private static function serverSdkKeyOfAUsableConnection(
        #[\SensitiveParameter]
        ?string $serverSdkKey,
        ConnectionOptions $connectionOptions,
    ): string {
        $providedServerSdkKey = OptionRules::nonBlankServerSdkKey($serverSdkKey, 'the client cannot be instantiated');
        OptionRules::rejectUnusableConnectionToConfigDirector($connectionOptions);

        return $providedServerSdkKey;
    }
}
