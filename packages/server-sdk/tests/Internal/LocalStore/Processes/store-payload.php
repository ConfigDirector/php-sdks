<?php

declare(strict_types=1);

use ConfigDirector\Internal\LocalStore\LocalStore;
use ConfigDirector\Internal\LocalStore\StoredPayload;
use Psr\Log\NullLogger;

if (!isset($argv)) {
    throw new RuntimeException('This script takes its arguments from the command line.');
}
[, $autoloadFile, $storagePath, $serverSdkKey, $marker, $paddingBytes] = $argv;

require $autoloadFile;

$store = LocalStore::ofServerSdkKey($serverSdkKey, $storagePath === '' ? null : $storagePath, new NullLogger());
$isStored = $store->refreshLockAcquiredWithin(5_000)?->replaceStoredPayload(new StoredPayload(
    ['configs' => [], 'marker' => $marker, 'padding' => str_repeat('x', (int) $paddingBytes)],
    1_794_477_600_123,
    "session-of-{$marker}",
));

echo $isStored === true ? 'stored' : 'not-stored';
