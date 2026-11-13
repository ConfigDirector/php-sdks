<?php

declare(strict_types=1);

use ConfigDirector\Internal\LocalStore\LocalStore;
use ConfigDirector\Internal\LocalStore\StoredPayload;
use Psr\Log\NullLogger;

if (!isset($argv)) {
    throw new RuntimeException('This script takes its arguments from the command line.');
}
[, $autoloadFile, $storagePath, $serverSdkKey, $replacements, $paddingBytes] = $argv;

require $autoloadFile;

$store = LocalStore::ofServerSdkKey($serverSdkKey, $storagePath, new NullLogger());
$replacementsStored = 0;
for ($replacement = 1; $replacement <= (int) $replacements; $replacement++) {
    $refreshLock = $store->refreshLockAcquiredWithin(5_000);
    $isStored = $refreshLock?->replaceStoredPayload(new StoredPayload(
        ['configs' => [], 'padding' => str_repeat(chr(ord('a') + $replacement % 26), (int) $paddingBytes)],
        $replacement,
        'session-1',
    ));
    $refreshLock?->release();
    $replacementsStored += $isStored === true ? 1 : 0;
}

echo $replacementsStored;
