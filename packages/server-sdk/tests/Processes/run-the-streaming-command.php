<?php

declare(strict_types=1);

if (!isset($argv)) {
    throw new RuntimeException('This script takes its arguments from the command line.');
}
[, $_composer_autoload_path] = $argv;

require dirname(__DIR__, 2) . '/bin/configdirector-stream';
