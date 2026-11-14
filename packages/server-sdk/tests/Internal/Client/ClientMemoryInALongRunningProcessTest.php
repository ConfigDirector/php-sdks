<?php

declare(strict_types=1);

namespace ConfigDirector\Tests\Internal\Client;

use ConfigDirector\ConfigDirector;
use ConfigDirector\ConfigDirectorClient;
use ConfigDirector\ConnectionOptions;
use ConfigDirector\Context;
use ConfigDirector\Internal\Client\DefaultConfigDirectorClient;
use ConfigDirector\Internal\Client\EventHandlers;
use ConfigDirector\Internal\Connection\LocalStoreConnection;
use ConfigDirector\Internal\Identity\SdkIdentity;
use ConfigDirector\Internal\LocalStore\LocalStore;
use ConfigDirector\Internal\LocalStore\LocalStoreOpenedOnFirstUse;
use ConfigDirector\Internal\LocalStore\StoredPayload;
use ConfigDirector\Internal\Polling\PollingEndpoint;
use ConfigDirector\Internal\Polling\PollingInterval;
use ConfigDirector\Internal\Polling\PollingTransport;
use ConfigDirector\Internal\Telemetry\EventReporter;
use ConfigDirector\Internal\Telemetry\LocalStoreTelemetry;
use ConfigDirector\Internal\Telemetry\TelemetryEndpoint;
use ConfigDirector\Internal\Time\ClientLifetime;
use ConfigDirector\Metadata;
use ConfigDirector\Testing\ConfigDirectorTesting;
use ConfigDirector\Tests\Support\ScriptedSdkServer;
use ConfigDirector\Tests\Support\SettableClock;
use ConfigDirector\Tests\Support\TemporaryFolder;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ClientMemoryInALongRunningProcessTest extends TestCase
{
    private const int NOVEMBER_12_2026_AT_NOON = 1_794_484_800_000;

    private const int FLUSH_INTERVAL_MILLISECONDS = 30_000;

    private const int EVENT_QUEUE_LIMIT = 5_000;

    private const int MARGIN_BYTES = 256 * 1024;

    private string $storagePath;

    private SettableClock $clock;

    private ScriptedSdkServer $server;

    #[\Override]
    protected function setUp(): void
    {
        $this->storagePath = TemporaryFolder::create();
        $this->clock = new SettableClock(self::NOVEMBER_12_2026_AT_NOON);
        $this->server = ScriptedSdkServer::answeringEveryRequestWith([
            'status' => 200,
            'body' => self::payloadServingASearchEngineOf('keyword'),
        ]);
    }

    #[\Override]
    protected function tearDown(): void
    {
        gc_enable();
        $this->server->stop();
        TemporaryFolder::remove($this->storagePath);
    }

    public function testMemoryAfter100000ReadsIsWithin256KibibytesOfMemoryAfter1000(): void
    {
        $client = $this->initializedClient();
        $valuesServed = [];

        $this->readTheSearchEngineForOneOf500UsersEvery10Milliseconds($client, 1_000, $valuesServed);
        $bytesAfter1000Reads = memory_get_usage();
        $this->readTheSearchEngineForOneOf500UsersEvery10Milliseconds($client, 99_000, $valuesServed);
        $bytesAfter100000Reads = memory_get_usage();

        self::assertSame(['keyword' => 50_000, 'vector' => 50_000], $valuesServed);
        self::assertLessThan(self::MARGIN_BYTES, $bytesAfter100000Reads - $bytesAfter1000Reads);
    }

    public function testMemoryAfter1000RefreshesOfTheLocalStoreIsWithin256KibibytesOfMemoryAfter10(): void
    {
        $client = $this->initializedClient();
        $this->clock->epochMilliseconds += self::FLUSH_INTERVAL_MILLISECONDS + 1;

        $this->readTheSearchEngineAfterEachOfTheRefreshes($client, firstRefresh: 1, lastRefresh: 10);
        $bytesAfter10Refreshes = memory_get_usage();
        $valueServedAfterTheLastRefresh = $this->readTheSearchEngineAfterEachOfTheRefreshes(
            $client,
            firstRefresh: 11,
            lastRefresh: 1_000,
        );
        $bytesAfter1000Refreshes = memory_get_usage();

        self::assertSame('engine-of-refresh-1000', $valueServedAfterTheLastRefresh);
        self::assertLessThan(self::MARGIN_BYTES, $bytesAfter1000Refreshes - $bytesAfter10Refreshes);
    }

    public function testMemoryAfterWatchingAndUnwatching100000ConfigKeysIsWithin256KibibytesOfMemoryAfter1000(): void
    {
        $client = $this->initializedClient();

        self::watchAndUnwatchTheConfigKeysNumbered($client, firstKey: 1, lastKey: 1_000);
        $bytesAfter1000ConfigKeys = memory_get_usage();
        self::watchAndUnwatchTheConfigKeysNumbered($client, firstKey: 1_001, lastKey: 100_000);
        $bytesAfter100000ConfigKeys = memory_get_usage();

        self::assertLessThan(self::MARGIN_BYTES, $bytesAfter100000ConfigKeys - $bytesAfter1000ConfigKeys);
    }

    public function testWithoutTheCycleCollectorMemoryAfter1000ClientsWereDisposedOfIsWithin256KibibytesOfMemoryAfter100(): void
    {
        gc_disable();

        $this->serveRequestsEachWithAClientOfItsOwn(requests: 100);
        $bytesAfter100Clients = memory_get_usage();
        $this->serveRequestsEachWithAClientOfItsOwn(requests: 900);
        $bytesAfter1000Clients = memory_get_usage();

        self::assertLessThan(self::MARGIN_BYTES, $bytesAfter1000Clients - $bytesAfter100Clients);
    }

    public function testWithoutTheCycleCollectorMemoryAfter1000TestClientsWereDisposedOfIsWithin256KibibytesOfMemoryAfter100(): void
    {
        gc_disable();

        self::readFromATestClientOfItsOwn(tests: 100);
        $bytesAfter100TestClients = memory_get_usage();
        self::readFromATestClientOfItsOwn(tests: 900);
        $bytesAfter1000TestClients = memory_get_usage();

        self::assertLessThan(self::MARGIN_BYTES, $bytesAfter1000TestClients - $bytesAfter100TestClients);
    }

    /**
     * @param array<string, int> $valuesServed
     */
    private function readTheSearchEngineForOneOf500UsersEvery10Milliseconds(
        ConfigDirectorClient $client,
        int $reads,
        array &$valuesServed,
    ): void {
        for ($read = 1; $read <= $reads; $read++) {
            $this->clock->epochMilliseconds += 10;
            $user = $read % 500;
            $searchEngine = $client->getValue(
                'search-engine',
                'in-code-default',
                new Context(id: "user-{$user}", traits: ['plan' => $user % 2 === 0 ? 'pro' : 'free']),
            );
            $valuesServed[$searchEngine] = ($valuesServed[$searchEngine] ?? 0) + 1;
        }
    }

    private function readTheSearchEngineAfterEachOfTheRefreshes(
        ConfigDirectorClient $client,
        int $firstRefresh,
        int $lastRefresh,
    ): string {
        $store = LocalStore::ofServerSdkKey('server-sdk-key', $this->storagePath, new NullLogger());
        $searchEngine = '';
        for ($refresh = $firstRefresh; $refresh <= $lastRefresh; $refresh++) {
            $this->clock->epochMilliseconds += 1_000;
            $refreshLock = $store->refreshLockAcquiredWithin(0);
            self::assertNotNull($refreshLock);
            $refreshLock->replaceStoredPayload(new StoredPayload(
                self::payloadServingASearchEngineOf("engine-of-refresh-{$refresh}"),
                $this->clock->epochMilliseconds,
                'session-1',
            ));
            $refreshLock->release();

            $searchEngine = $client->getValue('search-engine', 'in-code-default');
        }

        return $searchEngine;
    }

    private static function watchAndUnwatchTheConfigKeysNumbered(
        ConfigDirectorClient $client,
        int $firstKey,
        int $lastKey,
    ): void {
        for ($key = $firstKey; $key <= $lastKey; $key++) {
            $unwatch = $client->watch("config-{$key}", false, static function (): void {});
            $unwatch();

            $callback = static function (): void {};
            $client->watch("other-config-{$key}", false, $callback);
            $client->unwatch("other-config-{$key}", $callback);
        }
    }

    private function serveRequestsEachWithAClientOfItsOwn(int $requests): void
    {
        for ($request = 1; $request <= $requests; $request++) {
            $client = ConfigDirector::createClient(
                serverSdkKey: 'server-sdk-key',
                connection: new ConnectionOptions(url: $this->server->url, storagePath: $this->storagePath),
                logger: new NullLogger(),
            );
            $client->initialize();
            $client->getValue('search-engine', 'in-code-default', new Context(id: 'user-1'));
            $client->dispose();
        }
    }

    private static function readFromATestClientOfItsOwn(int $tests): void
    {
        for ($test = 1; $test <= $tests; $test++) {
            $testClient = ConfigDirectorTesting::createTestClient(
                values: ['search-engine' => 'vector'],
                logger: new NullLogger(),
            );
            $testClient->client->initialize();
            $testClient->client->getValue('search-engine', 'in-code-default');
            $testClient->client->dispose();
        }
    }

    private function initializedClient(): ConfigDirectorClient
    {
        $logger = new NullLogger();
        $clock = $this->clock;
        $serverUrl = $this->server->url;
        $eventHandlers = new EventHandlers($logger);
        $clientLifetime = new ClientLifetime($clock, self::FLUSH_INTERVAL_MILLISECONDS);
        $localStore = new LocalStoreOpenedOnFirstUse('server-sdk-key', $this->storagePath, $logger);
        $client = new DefaultConfigDirectorClient(
            connection: new LocalStoreConnection(
                static fn(\Closure $reportPayloadReceivedByAPoll): PollingTransport => new PollingTransport(
                    store: $localStore->store(),
                    endpoint: new PollingEndpoint(
                        $serverUrl,
                        'server-sdk-key',
                        new Metadata(),
                        SdkIdentity::ofTheServerSdk(),
                    ),
                    pollingInterval: PollingInterval::ofConfiguredSeconds(60),
                    timeoutMilliseconds: 3_000,
                    clientLifetime: $clientLifetime,
                    clock: $clock,
                    logger: $logger,
                    reportUnrecoverableError: $eventHandlers->deliverConnectionError(...),
                    reportPayloadReceivedByAPoll: $reportPayloadReceivedByAPoll,
                ),
                $logger,
            ),
            metadata: new Metadata(),
            timeoutMilliseconds: 3_000,
            logger: $logger,
            eventHandlers: $eventHandlers,
            telemetry: new LocalStoreTelemetry(
                $localStore,
                new EventReporter(
                    new TelemetryEndpoint($serverUrl, 'server-sdk-key', new Metadata(), SdkIdentity::ofTheServerSdk()),
                    self::EVENT_QUEUE_LIMIT,
                    $clock,
                    $logger,
                ),
                self::EVENT_QUEUE_LIMIT,
                self::FLUSH_INTERVAL_MILLISECONDS,
                $clientLifetime,
                $clock,
                $logger,
            ),
        );
        $client->initialize();

        return $client;
    }

    /**
     * @return array<string, mixed>
     */
    private static function payloadServingASearchEngineOf(string $defaultTargetingRuleValue): array
    {
        return [
            'kind' => 'full',
            'configs' => [
                'search-engine' => [
                    'id' => 'config-search-engine',
                    'key' => 'search-engine',
                    'type' => 'string',
                    'variations' => [],
                    'target' => [
                        'rules' => [
                            [
                                'id' => 'rule-plan-segment',
                                'order' => 0,
                                'type' => 'conditional',
                                'target' => 'value',
                                'percentages' => null,
                                'value' => 'vector',
                                'valueId' => 'value-vector',
                                'conditions' => [
                                    [
                                        'kind' => 'segment',
                                        'id' => 'condition-plan-segment',
                                        'operator' => 'in',
                                        'segmentId' => 'segment-of-the-pro-plan',
                                    ],
                                ],
                            ],
                        ],
                        'defaultValue' => $defaultTargetingRuleValue,
                        'defaultValueId' => 'value-of-the-default-targeting-rule',
                    ],
                ],
            ],
            'segments' => [
                'segment-of-the-pro-plan' => [
                    'groups' => [
                        [
                            [
                                'kind' => 'attribute',
                                'id' => 'condition-pro-plan',
                                'attribute' => 'traits',
                                'trait' => '/plan',
                                'operator' => 'equals',
                                'targetType' => 'text',
                                'targetValues' => ['pro'],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
