<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Connection {
    function usleep(int $microseconds): void
    {
        echo "woke early\n";
        \usleep(intdiv($microseconds, 2));
    }
}

namespace {
    use ConfigDirector\Testing\ConfigDirectorTesting;
    use Psr\Log\NullLogger;

    if (!isset($argv)) {
        throw new RuntimeException('This script takes its arguments from the command line.');
    }
    [, $autoloadFile] = $argv;

    require $autoloadFile;

    $testClient = ConfigDirectorTesting::createTestClient(timeout: 50, logger: new NullLogger());
    $testClient->holdInitialization();

    $startedAt = hrtime(true);
    $testClient->client->initialize();

    echo 'waited ' . intdiv(hrtime(true) - $startedAt, 1_000_000) . " milliseconds\n";
}
