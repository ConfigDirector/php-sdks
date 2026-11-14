<?php

declare(strict_types=1);

use ConfigDirector\ConfigDirector;
use ConfigDirector\ConfigDirectorConnectionException;
use ConfigDirector\ConfigDirectorException;
use ConfigDirector\ConnectionOptions;
use ConfigDirector\Metadata;
use ConfigDirector\TelemetryOptions;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

if (!isset($argv)) {
    throw new RuntimeException('This script takes its arguments from the command line.');
}
[, $autoloadFile, $storagePath, $serverUrl, $flushInterval] = $argv;

require $autoloadFile;

$logger = new class extends AbstractLogger {
    /**
     * @param array<array-key, mixed> $context
     */
    #[Override]
    public function log($level, string|Stringable $message, array $context = []): void
    {
        if ($level !== LogLevel::DEBUG && is_string($level)) {
            echo "{$level}-logged\n";
        }
    }
};
$streamingProcess = ConfigDirector::createStreamingProcess(
    serverSdkKey: 'server-sdk-key',
    metadata: new Metadata(appName: 'checkout', appVersion: '4.2.0'),
    connection: new ConnectionOptions(url: $serverUrl, storagePath: $storagePath),
    logger: $logger,
    telemetry: new TelemetryOptions(flushInterval: (int) $flushInterval),
);

try {
    $streamingProcess->run();
    echo "run-returned\n";
} catch (ConfigDirectorConnectionException $exception) {
    echo "run-threw-connection-exception {$exception->status}\n";
} catch (ConfigDirectorException) {
    echo "run-threw-configdirector-exception\n";
}
