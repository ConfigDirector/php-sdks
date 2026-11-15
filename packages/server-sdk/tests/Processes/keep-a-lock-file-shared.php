<?php

declare(strict_types=1);

if (!isset($argv)) {
    throw new RuntimeException('This script takes its arguments from the command line.');
}
[, , $lockFilePath, $fileAwaitedBeforeLettingGoOfTheLock] = $argv;

$lockFile = fopen($lockFilePath, 'cb');
echo $lockFile !== false && flock($lockFile, LOCK_SH | LOCK_NB) ? "locked\n" : "not-locked\n";

while (!file_exists($fileAwaitedBeforeLettingGoOfTheLock)) {
    usleep(1_000);
}
