<?php

declare(strict_types=1);

use ConfigDirector\ConfigDirectorConnectionException;
use ConfigDirector\ConnectionErrorEvent;
use ConfigDirector\Internal\Client\DefaultConfigDirectorClient;
use ConfigDirector\Internal\Client\EventHandlers;
use ConfigDirector\Internal\Connection\LocalStoreConnection;
use ConfigDirector\Internal\Identity\SdkIdentity;
use ConfigDirector\Internal\LocalStore\LocalStore;
use ConfigDirector\Internal\Polling\PollingEndpoint;
use ConfigDirector\Internal\Polling\PollingInterval;
use ConfigDirector\Internal\Polling\PollingTransport;
use ConfigDirector\Internal\Telemetry\DiscardedTelemetry;
use ConfigDirector\Internal\Time\ClientLifetime;
use ConfigDirector\Metadata;
use ConfigDirector\Tests\Support\SettableClock;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

if (!isset($argv)) {
    throw new RuntimeException('This script takes its arguments from the command line.');
}
[, $autoloadFile, $storagePath, $serverUrl, $nowEpochMilliseconds, $fileAwaitedBeforeEnding] = $argv;

require $autoloadFile;

$logger = new class extends AbstractLogger {
    /**
     * @param array<array-key, mixed> $context
     */
    #[Override]
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $exception = $context['exception'] ?? null;
        if ($level === LogLevel::ERROR) {
            echo 'error-logged ', $exception instanceof ConfigDirectorConnectionException ? $exception->status : '', "\n";
        }
    }
};
$eventHandlers = new EventHandlers($logger);
$clock = new SettableClock((int) $nowEpochMilliseconds);
$transport = new PollingTransport(
    store: LocalStore::ofServerSdkKey('server-sdk-key', $storagePath, $logger),
    endpoint: new PollingEndpoint($serverUrl, 'server-sdk-key', new Metadata(), SdkIdentity::ofTheServerSdk()),
    pollingInterval: PollingInterval::ofConfiguredSeconds(60),
    timeoutMilliseconds: 3_000,
    clientLifetime: new ClientLifetime($clock, 30_000),
    clock: $clock,
    logger: $logger,
    reportUnrecoverableError: $eventHandlers->deliverConnectionError(...),
    reportPayloadReceivedByAPoll: static function (): void {},
);
$client = new DefaultConfigDirectorClient(
    connection: new LocalStoreConnection(static fn(): PollingTransport => $transport, $logger),
    metadata: new Metadata(),
    timeoutMilliseconds: 3_000,
    logger: $logger,
    eventHandlers: $eventHandlers,
    telemetry: new DiscardedTelemetry(),
);
$client->on('connectionError', static function (ConnectionErrorEvent $event): void {
    echo 'connection-error ', $event->error instanceof ConfigDirectorConnectionException ? $event->error->status : '', "\n";
});

$client->initialize();
echo $client->isReady() ? "ready\n" : "not-ready\n";

while ($fileAwaitedBeforeEnding !== '' && !file_exists($fileAwaitedBeforeEnding)) {
    usleep(1_000);
}
