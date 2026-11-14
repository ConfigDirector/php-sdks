<?php

declare(strict_types=1);

use ConfigDirector\Internal\LocalStore\LocalStore;
use ConfigDirector\Internal\LocalStore\StoredPayload;
use Psr\Log\NullLogger;

const URL_NOTHING_ANSWERS_ON = 'http://127.0.0.1:9';
const EVALUATIONS_OF_A_REQUEST = 20;
const REQUESTS_MEASURED = 100;
const REQUESTS_BEFORE_THE_MEASURED_ONES = 5;
const REQUESTS_BETWEEN_TWO_REFRESHES_OF_THE_STORE = 20;

[, $autoloadFile, $configCount] = $argv;

require $autoloadFile;
require __DIR__ . '/configs.php';

/**
 * @return array<string, mixed>
 */
function payloadOfConfigsWithRulesAndSegments(int $configCount): array
{
    $segments = [];
    for ($segment = 1; $segment <= SEGMENTS_OF_THE_BENCHMARK; $segment++) {
        $segments["segment-{$segment}"] = ['groups' => [
            [
                [
                    'kind' => 'attribute', 'id' => "segment-{$segment}-plan", 'attribute' => 'traits',
                    'trait' => '/plan', 'operator' => 'is one of', 'targetType' => 'text',
                    'targetValues' => ['enterprise', "plan-{$segment}"],
                ],
                [
                    'kind' => 'attribute', 'id' => "segment-{$segment}-seats", 'attribute' => 'traits',
                    'trait' => '/account/seats', 'operator' => '>', 'targetType' => 'number',
                    'targetValues' => [(string) ($segment * 10)],
                ],
            ],
            [
                [
                    'kind' => 'attribute', 'id' => "segment-{$segment}-email", 'attribute' => 'traits',
                    'trait' => '/email', 'operator' => 'ends with any of', 'targetType' => 'text',
                    'targetValues' => ["@customer-{$segment}.example.com"],
                ],
            ],
        ]];
    }

    $configs = [];
    for ($config = 1; $config <= $configCount; $config++) {
        $type = typeOfTheConfigNumbered($config);
        [$valueOfTheRules, $defaultTargetingRuleValue]
            = VALUE_OF_THE_RULES_AND_DEFAULT_TARGETING_RULE_VALUE_BY_CONFIG_TYPE[$type];
        $configs["config-{$config}"] = [
            'id' => "id-of-config-{$config}",
            'key' => "config-{$config}",
            'type' => $type,
            'typeConstraints' => null,
            'variations' => [
                ['id' => "variation-{$config}-a", 'name' => 'A', 'value' => $valueOfTheRules],
                ['id' => "variation-{$config}-b", 'name' => 'B', 'value' => $defaultTargetingRuleValue],
            ],
            'target' => [
                'environmentId' => 'environment-1',
                'rules' => [
                    [
                        'id' => "rule-{$config}-segment", 'order' => 0, 'type' => 'conditional', 'target' => 'value',
                        'percentages' => null, 'value' => $defaultTargetingRuleValue, 'valueId' => "value-{$config}-b",
                        'conditions' => [[
                            'kind' => 'segment', 'id' => "condition-{$config}-segment", 'operator' => 'in',
                            'segmentId' => 'segment-' . ($config % SEGMENTS_OF_THE_BENCHMARK + 1),
                        ]],
                    ],
                    [
                        'id' => "rule-{$config}-rollout", 'order' => 1, 'type' => 'conditional',
                        'target' => 'percentage',
                        'percentages' => [
                            [
                                'id' => "share-{$config}-a", 'percentage' => 10.0, 'value' => $valueOfTheRules,
                                'valueId' => "value-{$config}-a", 'variationId' => "variation-{$config}-a",
                            ],
                            [
                                'id' => "share-{$config}-b", 'percentage' => 90.0,
                                'value' => $defaultTargetingRuleValue, 'valueId' => "value-{$config}-b",
                                'variationId' => "variation-{$config}-b",
                            ],
                        ],
                        'value' => null, 'valueId' => null,
                        'conditions' => [[
                            'kind' => 'attribute', 'id' => "condition-{$config}-beta", 'attribute' => 'traits',
                            'trait' => '/beta', 'operator' => 'equals', 'targetType' => 'text',
                            'targetValues' => ['false'],
                        ]],
                    ],
                    [
                        'id' => "rule-{$config}-country", 'order' => 2, 'type' => 'conditional', 'target' => 'value',
                        'percentages' => null, 'value' => $valueOfTheRules, 'valueId' => "value-{$config}-a",
                        'conditions' => [[
                            'kind' => 'attribute', 'id' => "condition-{$config}-country", 'attribute' => 'traits',
                            'trait' => '/country', 'operator' => 'is one of', 'targetType' => 'text',
                            'targetValues' => ['NZ', 'AU', 'CL'],
                        ]],
                    ],
                ],
                'defaultValue' => $defaultTargetingRuleValue,
                'defaultValueId' => "value-{$config}-b",
            ],
        ];
    }

    return ['kind' => 'full', 'configs' => $configs, 'segments' => $segments];
}

function removeTheFolder(string $folder): void
{
    foreach (array_diff(scandir($folder) ?: [], ['.', '..']) as $entry) {
        $path = $folder . DIRECTORY_SEPARATOR . $entry;
        is_dir($path) ? removeTheFolder($path) : unlink($path);
    }
    rmdir($folder);
}

$folder = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'configdirector-benchmark-' . bin2hex(random_bytes(8));
$storagePath = $folder . DIRECTORY_SEPARATOR . 'storage';
$compiledScripts = $folder . DIRECTORY_SEPARATOR . 'compiled-scripts';
mkdir($storagePath, 0o700, true);
mkdir($compiledScripts, 0o700);

$payload = payloadOfConfigsWithRulesAndSegments((int) $configCount);
$store = LocalStore::ofServerSdkKey('benchmark-server-sdk-key', $storagePath, new NullLogger());
$confirmTheStoredPayloadFresh = static function () use ($store, $payload): void {
    $refreshLock = $store->refreshLockAcquiredWithin(1_000);
    $refreshLock?->replaceStoredPayload(
        new StoredPayload($payload, (int) floor(microtime(true) * 1_000), 'session-of-the-benchmark'),
    );
    $refreshLock?->release();
};

$phpOptionsByRun = ['every script compiled in each process' => []];
if (extension_loaded('Zend OPcache')) {
    $phpOptionsByRun['compiled scripts reused, as under PHP-FPM with OPcache'] = [
        '-d', 'opcache.enable_cli=1',
        '-d', "opcache.file_cache={$compiledScripts}",
        '-d', 'opcache.file_cache_only=1',
    ];
}

try {
    $confirmTheStoredPayloadFresh();
    printf(
        "PHP %s on %s %s (%s), %d configs with rules and segments, payload file of %d KiB, %d requests\n",
        PHP_VERSION,
        PHP_OS_FAMILY,
        php_uname('r'),
        php_uname('m'),
        $configCount,
        intdiv((int) filesize($store->usableFolder . DIRECTORY_SEPARATOR . 'payload.json'), 1_024),
        REQUESTS_MEASURED,
    );

    foreach ($phpOptionsByRun as $run => $phpOptions) {
        $measurementsByPhase = [];
        $peakMemoryMebibytes = [];
        for ($request = 0; $request < REQUESTS_BEFORE_THE_MEASURED_ONES + REQUESTS_MEASURED; $request++) {
            if ($request % REQUESTS_BETWEEN_TWO_REFRESHES_OF_THE_STORE === 0) {
                $confirmTheStoredPayloadFresh();
            }

            $process = proc_open(
                [
                    PHP_BINARY, ...$phpOptions, __DIR__ . '/server-sdk-request.php', $autoloadFile, $storagePath,
                    URL_NOTHING_ANSWERS_ON, (string) EVALUATIONS_OF_A_REQUEST,
                ],
                [1 => ['pipe', 'w']],
                $pipes,
            );
            $measurements = json_decode((string) stream_get_contents($pipes[1]), true);
            proc_close($process);
            if (!is_array($measurements) || ($measurements['servedTheStoredValue'] ?? false) !== true) {
                throw new RuntimeException('A request of the benchmark did not serve the stored value.');
            }

            if ($request < REQUESTS_BEFORE_THE_MEASURED_ONES) {
                continue;
            }

            $peakMemoryMebibytes[] = $measurements['peakMemoryMebibytes'];
            unset($measurements['servedTheStoredValue'], $measurements['peakMemoryMebibytes']);
            foreach ($measurements as $phase => $milliseconds) {
                $measurementsByPhase[$phase][] = $milliseconds;
            }
        }

        printf("\n%s\n", $run);
        foreach ($measurementsByPhase as $phase => $measurements) {
            sort($measurements);
            printf(
                "  %-34s median %8.3f ms   90th percentile %8.3f ms\n",
                $phase,
                $measurements[intdiv(count($measurements), 2)],
                $measurements[(int) (count($measurements) * 0.9)],
            );
        }
        printf("  %-34s %.1f MiB\n", 'peak memory of a request', max($peakMemoryMebibytes));
    }
} finally {
    removeTheFolder($folder);
}
