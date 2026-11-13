<?php

declare(strict_types=1);

use ConfigDirector\Testing\ConfigDirectorTesting;
use Psr\Log\NullLogger;

if (!isset($argv)) {
    throw new RuntimeException('This script takes its arguments from the command line.');
}
[, $autoloadFile] = $argv;

require $autoloadFile;

$testClient = ConfigDirectorTesting::createTestClient(
    values: ['new-checkout' => true, 'max-items' => 20],
    timeout: 10,
    logger: new NullLogger(),
);
$testClient->client->initialize();
$testClient->setValue('new-checkout', false);
$testClient->removeValue('max-items');
echo 'new-checkout: ' . var_export($testClient->client->getValue('new-checkout', true), true) . "\n";

$testClient->replaceValues(['max-items' => 30]);
$testClient->holdInitialization();
$testClient->client->initialize();
$testClient->completeInitialization();
echo 'max-items: ' . var_export($testClient->client->getValue('max-items', 0), true) . "\n";

$testClient->failInitialization();
$testClient->client->initialize();
$testClient->client->getAllConfigs();
$testClient->client->dispose();
echo "disposed\n";
