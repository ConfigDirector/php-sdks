<?php

declare(strict_types=1);

use ConfigDirector\Internal\LocalStore\LocalStore;
use ConfigDirector\Tests\Support\RecordingLogger;
use Psr\Log\LogLevel;

if (!isset($argv)) {
    throw new RuntimeException('This script takes its arguments from the command line.');
}
[, $autoloadFile, $storagePath, $serverSdkKey] = $argv;

require $autoloadFile;

$logger = new RecordingLogger();
$storedPayload = LocalStore::ofServerSdkKey($serverSdkKey, $storagePath === '' ? null : $storagePath, $logger)
    ->storedPayload();

echo json_encode(
    [
        'payload' => $storedPayload?->payload,
        'confirmedFreshAtEpochMilliseconds' => $storedPayload?->confirmedFreshAtEpochMilliseconds,
        'sessionId' => $storedPayload?->sessionId,
        'errorsLogged' => count($logger->contextsLoggedAt(LogLevel::ERROR)),
    ],
    JSON_THROW_ON_ERROR,
);
