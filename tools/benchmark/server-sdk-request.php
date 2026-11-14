<?php

declare(strict_types=1);

use ConfigDirector\ConfigDirector;
use ConfigDirector\ConnectionOptions;
use ConfigDirector\Context;
use ConfigDirector\TelemetryOptions;
use Psr\Log\NullLogger;

$startedAt = hrtime(true);
[, $autoloadFile, $storagePath, $urlNothingAnswersOn, $evaluations] = $argv;

require $autoloadFile;
$autoloadedAt = hrtime(true);

require __DIR__ . '/configs.php';

$client = ConfigDirector::createClient(
    serverSdkKey: 'benchmark-server-sdk-key',
    connection: new ConnectionOptions(url: $urlNothingAnswersOn, storagePath: $storagePath),
    logger: new NullLogger(),
    telemetry: new TelemetryOptions(flushInterval: 3_600_000),
);
$createdAt = hrtime(true);

$client->initialize();
$initializedAt = hrtime(true);

$context = new Context(
    id: 'user-42',
    traits: ['plan' => 'pro', 'country' => 'NZ', 'beta' => 'true', 'email' => 'ada@customer-3.example.com'],
);
$firstValue = $client->getValue('config-1', IN_CODE_DEFAULT_VALUE_BY_CONFIG_TYPE['string'], $context);
$firstValueReadAt = hrtime(true);

for ($config = 2; $config <= (int) $evaluations; $config++) {
    $client->getValue(
        "config-{$config}",
        IN_CODE_DEFAULT_VALUE_BY_CONFIG_TYPE[typeOfTheConfigNumbered($config)],
        $context,
    );
}
$everyValueReadAt = hrtime(true);

$client->dispose();
$disposedAt = hrtime(true);

echo json_encode([
    'servedTheStoredValue' => $firstValue !== IN_CODE_DEFAULT_VALUE_BY_CONFIG_TYPE['string'],
    'require the autoloader' => ($autoloadedAt - $startedAt) / 1e6,
    'createClient' => ($createdAt - $autoloadedAt) / 1e6,
    'initialize()' => ($initializedAt - $createdAt) / 1e6,
    'first getValue' => ($firstValueReadAt - $initializedAt) / 1e6,
    'each later getValue' => ($everyValueReadAt - $firstValueReadAt) / 1e6 / max(1, (int) $evaluations - 1),
    "dispose() after {$evaluations} evaluations" => ($disposedAt - $everyValueReadAt) / 1e6,
    'peakMemoryMebibytes' => memory_get_peak_usage() / 1_048_576,
]), "\n";
