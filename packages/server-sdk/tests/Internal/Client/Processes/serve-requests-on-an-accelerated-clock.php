<?php

declare(strict_types=1);

use ConfigDirector\Context;
use ConfigDirector\Internal\Client\DefaultConfigDirectorClient;
use ConfigDirector\Internal\Client\EventHandlers;
use ConfigDirector\Internal\Connection\LocalStoreConnection;
use ConfigDirector\Internal\Identity\SdkIdentity;
use ConfigDirector\Internal\LocalStore\LocalStoreOpenedOnFirstUse;
use ConfigDirector\Internal\Polling\PollingEndpoint;
use ConfigDirector\Internal\Polling\PollingInterval;
use ConfigDirector\Internal\Polling\PollingTransport;
use ConfigDirector\Internal\Telemetry\EventReporter;
use ConfigDirector\Internal\Telemetry\LocalStoreTelemetry;
use ConfigDirector\Internal\Telemetry\TelemetryEndpoint;
use ConfigDirector\Internal\Time\ClientLifetime;
use ConfigDirector\Metadata;
use ConfigDirector\Tests\Support\AcceleratedClock;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

if (!isset($argv)) {
    throw new RuntimeException('This script takes its arguments from the command line.');
}
[
    ,
    $autoloadFile,
    $storagePath,
    $serverUrl,
    $clockStartedAtEpochMilliseconds,
    $timesFasterThanRealTime,
    $flushIntervalMilliseconds,
    $contextId,
    $fileOfItsRecord,
    $requestsToServe,
    $fileAwaitedBeforeTheFirstRequest,
    $fileThatEndsTheRequests,
] = $argv;

require $autoloadFile;

const SERVER_SDK_KEY = 'server-sdk-key';
const EVENT_QUEUE_LIMIT = 5_000;
const TIMEOUT_MILLISECONDS = 3_000;
const LONGEST_WAIT_BEFORE_THE_FIRST_REQUEST_MICROSECONDS = 1_000_000;
const SHORTEST_WORK_OF_THE_APPLICATION_MICROSECONDS = 300_000;
const LONGEST_WORK_OF_THE_APPLICATION_MICROSECONDS = 500_000;

$logger = new class ($fileOfItsRecord) extends AbstractLogger {
    public function __construct(private readonly string $fileOfItsRecord) {}

    /**
     * @param array<array-key, mixed> $context
     */
    #[Override]
    public function log($level, string|Stringable $message, array $context = []): void
    {
        if ($level === LogLevel::WARNING || $level === LogLevel::ERROR) {
            $scalarsOfTheContext = json_encode(array_filter($context, is_scalar(...)));
            file_put_contents($this->fileOfItsRecord, "logged {$message} {$scalarsOfTheContext}\n", FILE_APPEND);
        }
    }
};
$clock = new AcceleratedClock((int) $clockStartedAtEpochMilliseconds, (int) $timesFasterThanRealTime);

$sourceFilesOfTheSdk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 4) . '/src'));
foreach ($sourceFilesOfTheSdk as $sourceFile) {
    if ($sourceFile instanceof SplFileInfo && $sourceFile->getExtension() === 'php') {
        require_once $sourceFile->getPathname();
    }
}

echo "started\n";
if ($fileAwaitedBeforeTheFirstRequest !== '') {
    while (!file_exists($fileAwaitedBeforeTheFirstRequest)) {
        usleep(1_000);
    }
    usleep(random_int(0, LONGEST_WAIT_BEFORE_THE_FIRST_REQUEST_MICROSECONDS));
}

for ($request = 1; $request <= (int) $requestsToServe && !file_exists($fileThatEndsTheRequests); $request++) {
    $eventHandlers = new EventHandlers($logger);
    $clientLifetime = new ClientLifetime($clock, (int) $flushIntervalMilliseconds);
    $localStore = new LocalStoreOpenedOnFirstUse(SERVER_SDK_KEY, $storagePath, $logger);
    $client = new DefaultConfigDirectorClient(
        connection: new LocalStoreConnection(
            static fn(Closure $reportPayloadReceivedByAPoll): PollingTransport => new PollingTransport(
                store: $localStore->store(),
                endpoint: new PollingEndpoint(
                    $serverUrl,
                    SERVER_SDK_KEY,
                    new Metadata(),
                    SdkIdentity::ofTheServerSdk(),
                ),
                pollingInterval: PollingInterval::ofConfiguredSeconds(60),
                timeoutMilliseconds: TIMEOUT_MILLISECONDS,
                clientLifetime: $clientLifetime,
                clock: $clock,
                logger: $logger,
                reportUnrecoverableError: $eventHandlers->deliverConnectionError(...),
                reportPayloadReceivedByAPoll: $reportPayloadReceivedByAPoll,
            ),
            $logger,
        ),
        metadata: new Metadata(),
        timeoutMilliseconds: TIMEOUT_MILLISECONDS,
        logger: $logger,
        eventHandlers: $eventHandlers,
        telemetry: new LocalStoreTelemetry(
            $localStore,
            new EventReporter(
                new TelemetryEndpoint($serverUrl, SERVER_SDK_KEY, new Metadata(), SdkIdentity::ofTheServerSdk()),
                EVENT_QUEUE_LIMIT,
                $clock,
                $logger,
            ),
            (int) $flushIntervalMilliseconds,
            $clientLifetime,
            $clock,
            $logger,
        ),
    );

    $client->initialize();
    $pageSize = $client->getValue('page-size', 10, new Context(id: $contextId));
    file_put_contents($fileOfItsRecord, "read {$pageSize}\n", FILE_APPEND);
    usleep(random_int(SHORTEST_WORK_OF_THE_APPLICATION_MICROSECONDS, LONGEST_WORK_OF_THE_APPLICATION_MICROSECONDS));

    $client->dispose();
}
