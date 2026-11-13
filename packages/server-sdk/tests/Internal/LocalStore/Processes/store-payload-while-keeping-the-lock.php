<?php

declare(strict_types=1);

use ConfigDirector\Internal\LocalStore\LocalStore;
use ConfigDirector\Internal\LocalStore\StoredPayload;
use Psr\Log\NullLogger;

if (!isset($argv)) {
    throw new RuntimeException('This script takes its arguments from the command line.');
}
[, $autoloadFile, $storagePath, $serverSdkKey, $millisecondsBeforeStoring, $millisecondsAfterStoring] = $argv;

require $autoloadFile;

$refreshLock = LocalStore::ofServerSdkKey($serverSdkKey, $storagePath, new NullLogger())->refreshLockAcquiredWithin(0);
echo $refreshLock === null ? "not-locked\n" : "locked\n";

usleep((int) $millisecondsBeforeStoring * 1_000);
$refreshLock?->replaceStoredPayload(
    new StoredPayload(['configs' => [], 'marker' => 'stored-by-the-holder'], 1_000, 'session-1'),
);
usleep((int) $millisecondsAfterStoring * 1_000);
$refreshLock?->release();
