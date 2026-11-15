<?php

declare(strict_types=1);

namespace ConfigDirector\Tests;

use ConfigDirector\ConfigDirector;
use ConfigDirector\ConfigDirectorClient;
use ConfigDirector\ConnectionOptions;
use ConfigDirector\Context;
use ConfigDirector\TelemetryOptions;
use ConfigDirector\Internal\LocalStore\LocalStore;
use ConfigDirector\Tests\Support\PhpProcess;
use ConfigDirector\Tests\Support\RecordingLogger;
use ConfigDirector\Tests\Support\ScriptedSdkServer;
use ConfigDirector\Tests\Support\TemporaryFolder;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;

final class ClientHostileInputTest extends TestCase
{
    private string $storagePath;

    private ScriptedSdkServer $server;

    #[\Override]
    protected function setUp(): void
    {
        $this->storagePath = TemporaryFolder::create();
        $this->server = ScriptedSdkServer::answeringEveryRequestWith([
            'status' => 200,
            'body' => [
                'kind' => 'full',
                'configs' => [
                    'page-size' => [
                        'id' => 'config-page-size',
                        'key' => 'page-size',
                        'type' => 'integer',
                        'target' => ['rules' => [], 'defaultValue' => '25', 'defaultValueId' => 'value-25'],
                    ],
                ],
            ],
        ]);
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->server->stop();
        TemporaryFolder::remove($this->storagePath);
    }

    public function testATelemetryFileHoldingTheLargestIntegerAsACountIsReportedWithoutThatCount(): void
    {
        $this->writeTheFileOfTheLocalStore('telemetry-tally.json', '{"collectingSince":1}');
        $this->writeTheFileOfTheLocalStore(
            'telemetry.jsonl',
            '{"collectingSince":1,"evaluationCounts":{"{\"key\":\"written-by-hand\"}":9223372036854775807}}' . "\n",
        );
        $client = $this->client();

        $client->initialize();
        $client->getValue('page-size', 10, new Context(id: 'user-42'));
        $client->dispose();

        self::assertSame([['page-size', 1]], $this->keysAndCountsOfTheEvaluationsReported());
    }

    public function testATelemetryFileIsReadUpToSixteenMebibytesAndWhatFollowsIsLeftOutOfTheReport(): void
    {
        $this->writeTheFileOfTheLocalStore('telemetry-tally.json', '{"collectingSince":1}');
        $this->writeTheFileOfTheLocalStore(
            'telemetry.jsonl',
            '{"collectingSince":1,"evaluationCounts":{"{\"key\":\"written-at-the-start\"}":2}}' . "\n"
            . str_repeat('x', 16 * 1024 * 1024) . "\n"
            . '{"collectingSince":1,"evaluationCounts":{"{\"key\":\"written-past-sixteen-mebibytes\"}":3}}' . "\n",
        );
        $client = $this->client();

        $client->initialize();
        $client->dispose();

        self::assertSame([['written-at-the-start', 2]], $this->keysAndCountsOfTheEvaluationsReported());
    }

    public function testADroppedCountOfTheLargestIntegerIsLeftOutOfTheReportThatCountsTheOthers(): void
    {
        $this->writeTheFileOfTheLocalStore('telemetry-tally.json', '{"collectingSince":1}');
        $this->writeTheFileOfTheLocalStore(
            'telemetry-dropped.jsonl',
            '{"droppedEvaluations":9223372036854775807,"droppedCapturedContexts":0}' . "\n"
            . '{"droppedEvaluations":2,"droppedCapturedContexts":1}' . "\n",
        );
        $client = $this->client();

        $client->initialize();
        $client->getValue('page-size', 10, new Context(id: 'user-42'));
        $client->dispose();

        self::assertSame([['page-size', 1]], $this->keysAndCountsOfTheEvaluationsReported());
        self::assertSame(
            ['evaluatedConfig' => 2, 'capturedContexts' => 1],
            $this->bodyOfTheOnlyReport()['droppedEvents'] ?? null,
        );
    }

    public function testAPollingPauseFileThatEndsAtTheLargestIntegerIsReadAsNoPause(): void
    {
        $this->writeTheFileOfTheLocalStore(
            'polling-pause-' . hash('sha256', $this->server->url . '/server/polling/v1') . '.json',
            '{"sessionId":"session-1","recordedAt":1,"until":9223372036854775807,"pollFailed":true,'
            . '"refusalStatus":null}',
        );
        $client = $this->client();

        $client->initialize();
        $pageSize = $client->getValue('page-size', 10);

        self::assertSame(25, $pageSize);
    }

    public function testAPayloadFileConfirmedFreshAtTheSmallestIntegerIsReadAsAnEmptyLocalStore(): void
    {
        $this->writeTheFileOfTheLocalStore(
            'payload.json',
            '{"confirmedFreshAt":-9223372036854775808,"sessionId":"session-1","payload":{"configs":{}}}',
        );
        $client = $this->client();

        $client->initialize();
        $pageSize = $client->getValue('page-size', 10);

        self::assertSame(25, $pageSize);
    }

    public function testAServedJsonValueOfFiveMegabytesThatIsNotJsonReturnsTheInCodeDefaultValue(): void
    {
        $this->storeThePayloadServing([
            'limits' => [
                'id' => 'config-limits',
                'key' => 'limits',
                'type' => 'json',
                'target' => [
                    'rules' => [],
                    'defaultValue' => '{"daily":' . str_repeat('9', 5_000_000),
                    'defaultValueId' => 'value-limits',
                ],
            ],
        ]);
        $client = $this->client();

        $client->initialize();
        $evaluation = $client->evaluate('limits', ['daily' => 10]);
        $client->dispose();

        self::assertSame(['daily' => 10], $evaluation->value);
        self::assertTrue($evaluation->isDefaultValue);
    }

    public function testATraitNestedTenThousandArraysDeepIsSearchedWithoutAnErrorAndMatchesNoRule(): void
    {
        $this->storeThePayloadServing([
            'plan-banner' => [
                'id' => 'config-plan-banner',
                'key' => 'plan-banner',
                'type' => 'string',
                'target' => [
                    'rules' => [
                        [
                            'id' => 'rule-pro-plan',
                            'order' => 0,
                            'type' => 'conditional',
                            'target' => 'value',
                            'value' => 'Pro banner',
                            'valueId' => 'value-pro-banner',
                            'conditions' => [
                                [
                                    'kind' => 'attribute',
                                    'id' => 'condition-pro-plan',
                                    'attribute' => 'traits',
                                    'trait' => '/account/0/0/0/plan',
                                    'operator' => 'equals',
                                    'targetType' => 'text',
                                    'targetValues' => ['pro'],
                                ],
                            ],
                        ],
                    ],
                    'defaultValue' => 'Standard banner',
                    'defaultValueId' => 'value-standard-banner',
                ],
            ],
        ]);
        $accountNestedTenThousandArraysDeep = ['plan' => 'pro'];
        for ($level = 1; $level <= 10_000; $level++) {
            $accountNestedTenThousandArraysDeep = [$accountNestedTenThousandArraysDeep];
        }
        $client = $this->client();

        $client->initialize();
        $banner = $client->getValue(
            'plan-banner',
            'In-code banner',
            new Context(id: 'user-42', traits: ['account' => $accountNestedTenThousandArraysDeep]),
        );
        $client->dispose();

        self::assertSame('Standard banner', $banner);
    }

    public function testAPayloadFileThatNeedsMoreMemoryThanARequestHasIsReadAsAnEmptyLocalStoreAndFetchedAgain(): void
    {
        $this->writeTheFileOfTheLocalStore(
            'payload.json',
            sprintf(
                '{"confirmedFreshAt":%d,"sessionId":"session-1","payload":{"configs":{},"padding":[%s]}}',
                (int) floor(microtime(true) * 1_000),
                implode(',', array_fill(0, 540_000, '{"a":1}')),
            ),
        );

        $finishedRequest = PhpProcess::running(
            __DIR__ . '/Processes/serve-a-request-that-reads-a-config.php',
            [$this->storagePath, $this->server->url, '30000', ''],
            phpSettings: ['memory_limit' => '64M'],
            fileForStandardError: $this->storagePath . DIRECTORY_SEPARATOR . 'standard-error.txt',
        )->finished();

        self::assertSame("page-size: 25\n", $finishedRequest->output);
        self::assertSame(0, $finishedRequest->exitCode);
    }

    public function testAPayloadFileThatNeedsMoreMemoryThanTheProcessHasLogsOneErrorNamingTheFile(): void
    {
        $this->writeTheFileOfTheLocalStore(
            'payload.json',
            sprintf(
                '{"confirmedFreshAt":%d,"sessionId":"session-1","payload":{"configs":{},"padding":[%s]}}',
                (int) floor(microtime(true) * 1_000),
                implode(',', array_fill(0, 540_000, '{"a":1}')),
            ),
        );
        $memoryLimitBeforeTheTest = (string) ini_get('memory_limit');
        $logger = new RecordingLogger();
        $store = LocalStore::ofServerSdkKey('server-sdk-key', $this->storagePath, $logger);

        ini_set('memory_limit', (string) (memory_get_usage() + 64 * 1_048_576));
        try {
            $storedPayload = $store->storedPayload();
            $store->storedPayload();
        } finally {
            ini_set('memory_limit', $memoryLimitBeforeTheTest);
        }

        self::assertNull($storedPayload);
        self::assertSame(
            [implode(DIRECTORY_SEPARATOR, [$this->storagePath, hash('sha256', 'server-sdk-key'), 'payload.json'])],
            array_column($logger->contextsLoggedAt(LogLevel::ERROR), 'file'),
        );
    }

    public function testALoggerThatReadsAConfigForEveryLineReceivesOneLineForASegmentThatCannotBeRead(): void
    {
        $this->writeTheFileOfTheLocalStore('payload.json', json_encode(
            [
                'confirmedFreshAt' => (int) floor(microtime(true) * 1_000),
                'sessionId' => 'session-1',
                'payload' => [
                    'kind' => 'full',
                    'configs' => [
                        'page-size' => [
                            'id' => 'config-page-size',
                            'key' => 'page-size',
                            'type' => 'integer',
                            'target' => ['rules' => [], 'defaultValue' => '25', 'defaultValueId' => 'value-25'],
                        ],
                    ],
                    'segments' => ['segment-without-groups' => ['name' => 'Beta testers']],
                ],
            ],
            JSON_THROW_ON_ERROR,
        ));
        $logger = new class extends AbstractLogger {
            public ?ConfigDirectorClient $clientItReads = null;

            /** @var list<string> */
            public array $segmentIdsItWasToldCannotBeRead = [];

            /**
             * @param array<array-key, mixed> $context
             */
            #[\Override]
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                if (is_string($context['segmentId'] ?? null)) {
                    $this->segmentIdsItWasToldCannotBeRead[] = $context['segmentId'];
                }
                $this->clientItReads?->getValue('page-size', 10);
            }
        };
        $client = ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            connection: new ConnectionOptions(url: $this->server->url, storagePath: $this->storagePath),
            logger: $logger,
        );
        $client->initialize();
        $logger->clientItReads = $client;

        $pageSize = $client->getValue('page-size', 10);

        self::assertSame(25, $pageSize);
        self::assertSame(['segment-without-groups'], $logger->segmentIdsItWasToldCannotBeRead);
    }

    public function testAReadWhoseInCodeDefaultValueContainsItselfReturnsItAndIsLeftOutOfTheReport(): void
    {
        $inCodeDefaultValue = ['retries' => 3];
        $inCodeDefaultValue['itself'] = &$inCodeDefaultValue;
        $client = $this->client(flushIntervalMilliseconds: 1);

        $client->initialize();
        $valueOfAConfigThatDoesNotExist = $client->getValue('retry-policy', $inCodeDefaultValue);
        $client->getValue('page-size', 10, new Context(id: 'user-42'));
        usleep(5_000);
        $client->dispose();

        self::assertSame(3, $valueOfAConfigThatDoesNotExist['retries']);
        self::assertSame([['page-size', 1]], $this->keysAndCountsOfTheEvaluationsReported());
    }

    private function client(int $flushIntervalMilliseconds = 30_000): ConfigDirectorClient
    {
        return ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            connection: new ConnectionOptions(url: $this->server->url, storagePath: $this->storagePath),
            logger: new NullLogger(),
            telemetry: new TelemetryOptions(flushInterval: $flushIntervalMilliseconds),
        );
    }

    /**
     * @param array<string, array<string, mixed>> $configs
     */
    private function storeThePayloadServing(array $configs): void
    {
        $this->writeTheFileOfTheLocalStore('payload.json', json_encode(
            [
                'confirmedFreshAt' => (int) floor(microtime(true) * 1_000),
                'sessionId' => 'session-1',
                'payload' => ['kind' => 'full', 'configs' => $configs],
            ],
            JSON_THROW_ON_ERROR,
        ));
    }

    private function writeTheFileOfTheLocalStore(string $name, string $contents): void
    {
        $folder = $this->storagePath . DIRECTORY_SEPARATOR . hash('sha256', 'server-sdk-key');
        if (!is_dir($folder)) {
            mkdir($folder, 0o700);
        }
        file_put_contents($folder . DIRECTORY_SEPARATOR . $name, $contents);
    }

    /**
     * @return list<array{mixed, mixed}>
     */
    private function keysAndCountsOfTheEvaluationsReported(): array
    {
        $aggregatedEvents = $this->bodyOfTheOnlyReport()['aggregatedEvents'] ?? null;
        $evaluations = is_array($aggregatedEvents) ? $aggregatedEvents['evaluatedConfig'] ?? null : null;

        $keysAndCounts = [];
        foreach (is_array($evaluations) ? $evaluations : [] as $evaluation) {
            $event = is_array($evaluation) ? $evaluation['event'] ?? null : null;
            $keysAndCounts[] = [
                is_array($event) ? $event['key'] ?? null : null,
                is_array($evaluation) ? $evaluation['count'] ?? null : null,
            ];
        }

        return $keysAndCounts;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function bodyOfTheOnlyReport(): array
    {
        $reports = array_values(array_filter(
            $this->server->requestsReceived(),
            static fn(array $request): bool => $request['path'] === '/server/telemetry/v1',
        ));
        self::assertCount(1, $reports);

        return is_array($reports[0]['body']) ? $reports[0]['body'] : [];
    }
}
