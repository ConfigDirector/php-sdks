<?php

declare(strict_types=1);

use ConfigDirector\ConfigDirector;
use ConfigDirector\ConnectionOptions;
use ConfigDirector\Context;
use ConfigDirector\TelemetryOptions;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

if (!isset($argv)) {
    throw new RuntimeException('This script takes its arguments from the command line.');
}
[, $autoloadFile, $storagePath, $serverUrl, $flushInterval, $fileAwaitedBeforeEnding] = $argv;

require $autoloadFile;

$logger = new class extends AbstractLogger {
    /**
     * @param array<array-key, mixed> $context
     */
    #[Override]
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $status = $context['status'] ?? null;
        if ($level === LogLevel::WARNING) {
            echo 'warning-logged ', is_int($status) ? $status : '', "\n";
        }
    }
};
$client = ConfigDirector::createClient(
    serverSdkKey: 'server-sdk-key',
    connection: new ConnectionOptions(url: $serverUrl, storagePath: $storagePath),
    logger: $logger,
    telemetry: new TelemetryOptions(flushInterval: (int) $flushInterval),
);
$client->initialize();
$pageSize = $client->getValue('page-size', 10, new Context(id: 'user-42'));
echo "page-size: {$pageSize}\n";

while ($fileAwaitedBeforeEnding !== '' && !file_exists($fileAwaitedBeforeEnding)) {
    usleep(1_000);
}
