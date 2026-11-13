<?php

declare(strict_types=1);

use ConfigDirector\Internal\LocalStore\LocalStore;
use Psr\Log\NullLogger;

if (!isset($argv)) {
    throw new RuntimeException('This script takes its arguments from the command line.');
}
[, $autoloadFile, $storagePath, $serverSdkKey, $millisecondsKept] = $argv;

require $autoloadFile;

$refreshLock = LocalStore::ofServerSdkKey($serverSdkKey, $storagePath, new NullLogger())->refreshLockAcquiredWithin(0);
echo $refreshLock === null ? "not-locked\n" : "locked\n";

usleep((int) $millisecondsKept * 1_000);
$refreshLock?->release();
