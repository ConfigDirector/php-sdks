<?php

declare(strict_types=1);

use ConfigDirector\Internal\LocalStore\LocalStore;
use ConfigDirector\Internal\LocalStore\StoredPayload;
use Psr\Log\NullLogger;

if (!isset($argv)) {
    throw new RuntimeException('This script takes its arguments from the command line.');
}
[, $autoloadFile, $storagePath, $serverSdkKey, $startingGateFolder, $processName] = $argv;

require $autoloadFile;

$store = LocalStore::ofServerSdkKey($serverSdkKey, $storagePath, new NullLogger());
touch($startingGateFolder . DIRECTORY_SEPARATOR . "ready-{$processName}");
while (!file_exists($startingGateFolder . DIRECTORY_SEPARATOR . 'go')) {
    usleep(1_000);
}

$hasFetched = false;
$storedPayload = $store->storedPayloadFilledWithin(
    10_000,
    function () use (&$hasFetched, $processName): StoredPayload {
        $hasFetched = true;
        usleep(200_000);

        return new StoredPayload(
            ['configs' => [], 'fetchedBy' => $processName],
            1_794_477_600_123,
            "session-of-{$processName}",
        );
    },
);

echo json_encode(
    [
        'process' => $processName,
        'hasFetched' => $hasFetched,
        'fetchedBy' => $storedPayload instanceof StoredPayload ? $storedPayload->payload['fetchedBy'] ?? null : null,
    ],
    JSON_THROW_ON_ERROR,
);
