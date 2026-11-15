<?php

declare(strict_types=1);

use ConfigDirector\Internal\LocalStore\AppendedLinesFile;
use ConfigDirector\Internal\LocalStore\FileLock;
use ConfigDirector\Internal\LocalStore\LineAppend;

if (!isset($argv)) {
    throw new RuntimeException('This script takes its arguments from the command line.');
}
[, $autoloadFile, $storeFolder, $maximumBytes, $process, $paddingLetter, $paddingBytes, $fileAwaited] = $argv;

require $autoloadFile;

$line = json_encode(['process' => $process, 'padding' => str_repeat($paddingLetter, (int) $paddingBytes)]);
if ($line === false) {
    throw new RuntimeException('The line could not be written as JSON.');
}
$appendedLinesFile = new AppendedLinesFile($storeFolder, 'telemetry.jsonl', (int) $maximumBytes);

echo "started\n";
while (!file_exists($fileAwaited)) {
    usleep(200);
}

$sharedLock = FileLock::sharedAcquiredWithin(5_000, $storeFolder . DIRECTORY_SEPARATOR . 'telemetry.lock');
$append = $sharedLock instanceof FileLock ? $appendedLinesFile->append($line) : null;
if ($sharedLock instanceof FileLock) {
    $sharedLock->release();
}

echo $append === LineAppend::Appended ? 'appended' : 'not-appended';
