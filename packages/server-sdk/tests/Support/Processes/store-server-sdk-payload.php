<?php

declare(strict_types=1);

use ConfigDirector\Internal\LocalStore\LocalStore;
use ConfigDirector\Internal\LocalStore\StoredPayload;
use Psr\Log\NullLogger;

if (!isset($argv)) {
    throw new RuntimeException('This script takes its arguments from the command line.');
}
[, $autoloadFile, $storagePath, $serverSdkKey, $payloadJson] = $argv;

require $autoloadFile;

$payload = json_decode($payloadJson, true, flags: JSON_THROW_ON_ERROR);
if (!is_array($payload)) {
    throw new RuntimeException('The server SDK payload must be a JSON object.');
}

$isStored = LocalStore::ofServerSdkKey($serverSdkKey, $storagePath, new NullLogger())
    ->refreshLockAcquiredWithin(5_000)
    ?->replaceStoredPayload(new StoredPayload($payload, (int) (microtime(true) * 1_000), 'session-of-another-process'));

echo $isStored === true ? 'stored' : 'not-stored';
