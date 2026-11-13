<?php

declare(strict_types=1);

namespace ConfigDirector\Tests\Internal\Client;

use ConfigDirector\ConfigDirectorClient;
use ConfigDirector\ConfigsUpdatedEvent;
use ConfigDirector\Context;
use ConfigDirector\Internal\Client\DefaultConfigDirectorClient;
use ConfigDirector\Internal\Client\EventHandlers;
use ConfigDirector\Internal\Connection\LocalStoreConnection;
use ConfigDirector\Internal\Identity\SdkIdentity;
use ConfigDirector\Internal\LocalStore\LocalStore;
use ConfigDirector\Internal\LocalStore\StoredPayload;
use ConfigDirector\Internal\Polling\PollingEndpoint;
use ConfigDirector\Internal\Polling\PollingInterval;
use ConfigDirector\Internal\Polling\PollingTransport;
use ConfigDirector\Internal\Telemetry\DiscardedTelemetry;
use ConfigDirector\Internal\Time\ClientLifetime;
use ConfigDirector\Metadata;
use ConfigDirector\Tests\Support\RecordingLogger;
use ConfigDirector\Tests\Support\ScriptedSdkServer;
use ConfigDirector\Tests\Support\SettableClock;
use ConfigDirector\Tests\Support\TemporaryFolder;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

/**
 * @phpstan-import-type ScriptedAnswer from ScriptedSdkServer
 */
final class ClientInALongRunningProcessTest extends TestCase
{
    private const int NOVEMBER_12_2026_AT_NOON = 1_794_484_800_000;

    private const int FLUSH_INTERVAL_MILLISECONDS = 30_000;

    private string $storagePath;

    private SettableClock $clock;

    private RecordingLogger $logger;

    private ?ScriptedSdkServer $server = null;

    #[\Override]
    protected function setUp(): void
    {
        $this->storagePath = TemporaryFolder::create();
        $this->clock = new SettableClock(self::NOVEMBER_12_2026_AT_NOON);
        $this->logger = new RecordingLogger();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->server?->stop();
        TemporaryFolder::remove($this->storagePath);
    }

    public function testAReadServesTheValueAnotherProcessStored(): void
    {
        $this->anotherProcessStores(self::payloadServing(['page-size' => '25']));
        $client = $this->initializedClient();
        $this->clock->epochMilliseconds += self::FLUSH_INTERVAL_MILLISECONDS + 1;
        $this->anotherProcessStores(self::payloadServing(['page-size' => '50']));

        $value = $client->getValue('page-size', 10);

        self::assertSame(50, $value);
    }

    public function testAClientNoOlderThanTheFlushIntervalKeepsTheValuesItInitializedWith(): void
    {
        $this->anotherProcessStores(self::payloadServing(['page-size' => '25']));
        $client = $this->initializedClient();
        $this->clock->epochMilliseconds += self::FLUSH_INTERVAL_MILLISECONDS;
        $this->anotherProcessStores(self::payloadServing(['page-size' => '50']));

        $value = $client->getValue('page-size', 10);

        self::assertSame(25, $value);
    }

    public function testAReadLessThanASecondAfterTheLastLookAtTheLocalStoreServesTheValuesHeld(): void
    {
        $this->anotherProcessStores(self::payloadServing(['page-size' => '25']));
        $client = $this->initializedClient();
        $this->clock->epochMilliseconds += self::FLUSH_INTERVAL_MILLISECONDS + 1;
        $client->getValue('page-size', 10);
        $this->anotherProcessStores(self::payloadServing(['page-size' => '50']));

        $this->clock->epochMilliseconds += 999;
        $value = $client->getValue('page-size', 10);

        self::assertSame(25, $value);
    }

    public function testAReadASecondAfterTheLastLookAtTheLocalStoreServesTheValueStoredMeanwhile(): void
    {
        $this->anotherProcessStores(self::payloadServing(['page-size' => '25']));
        $client = $this->initializedClient();
        $this->clock->epochMilliseconds += self::FLUSH_INTERVAL_MILLISECONDS + 1;
        $client->getValue('page-size', 10);
        $this->anotherProcessStores(self::payloadServing(['page-size' => '50']));

        $this->clock->epochMilliseconds += 1_000;
        $value = $client->getValue('page-size', 10);

        self::assertSame(50, $value);
    }

    public function testAReadServesAValueStoredInTheSameMillisecondAsTheOneBeforeIt(): void
    {
        $this->anotherProcessStores(self::payloadServing(['page-size' => '25']));
        $client = $this->initializedClient();
        $this->clock->epochMilliseconds += self::FLUSH_INTERVAL_MILLISECONDS + 1;
        $this->anotherProcessStores(self::payloadServing(['page-size' => '50']));
        $client->getValue('page-size', 10);
        $payloadReadLater = self::payloadServing(['page-size' => '75']);
        $payloadReadLater['timestamp'] = '2026-11-12T12:05:00.000000Z';
        $this->anotherProcessStores($payloadReadLater);

        $this->clock->setTheWallClockBackBy(1_000);
        $this->clock->epochMilliseconds += 1_000;
        $value = $client->getValue('page-size', 10);

        self::assertSame(75, $value);
    }

    public function testAReadServesTheMergedValuesAfterItsPollReceivedADelta(): void
    {
        $this->anotherProcessStores(self::payloadServing(['page-size' => '25', 'theme' => 'dark']));
        $delta = self::payloadServing(['page-size' => '50']);
        $delta['kind'] = 'delta';
        $delta['timestamp'] = '2026-11-12T12:01:00.000000Z';
        $client = $this->initializedClient([['status' => 200, 'body' => $delta]]);
        $updates = self::updatesReceivedBy($client);

        $this->clock->epochMilliseconds += 60_001;
        $pageSize = $client->getValue('page-size', 10);

        self::assertSame(50, $pageSize);
        self::assertSame('dark', $client->getValue('theme', 'light'));
        self::assertEquals([new ConfigsUpdatedEvent(['page-size'], [])], $updates->getArrayCopy());
    }

    public function testGetAllConfigsServesTheValueAnotherProcessStored(): void
    {
        $this->anotherProcessStores(self::payloadServing(['page-size' => '25']));
        $client = $this->initializedClient();
        $this->clock->epochMilliseconds += self::FLUSH_INTERVAL_MILLISECONDS + 1;
        $this->anotherProcessStores(self::payloadServing(['page-size' => '50']));

        $states = $client->getAllConfigs();

        self::assertSame('50', $states['page-size']->value);
    }

    public function testConfigsUpdatedNamesOnlyTheConfigWhoseDefinitionChanged(): void
    {
        $this->anotherProcessStores(self::payloadServing(['page-size' => '25', 'theme' => 'dark']));
        $client = $this->initializedClient();
        $updates = self::updatesReceivedBy($client);
        $this->clock->epochMilliseconds += self::FLUSH_INTERVAL_MILLISECONDS + 1;
        $this->anotherProcessStores(self::payloadServing(['page-size' => '50', 'theme' => 'dark']));

        $client->getValue('theme', 'light');

        self::assertEquals([new ConfigsUpdatedEvent(['page-size'], [])], $updates->getArrayCopy());
    }

    public function testConfigsUpdatedNamesAConfigThatIsNew(): void
    {
        $this->anotherProcessStores(self::payloadServing(['theme' => 'dark']));
        $client = $this->initializedClient();
        $updates = self::updatesReceivedBy($client);
        $this->clock->epochMilliseconds += self::FLUSH_INTERVAL_MILLISECONDS + 1;
        $this->anotherProcessStores(self::payloadServing(['theme' => 'dark', 'page-size' => '25']));

        $client->getValue('theme', 'light');

        self::assertEquals([new ConfigsUpdatedEvent(['page-size'], [])], $updates->getArrayCopy());
    }

    public function testConfigsUpdatedNamesAConfigThatIsGoneAsRemoved(): void
    {
        $this->anotherProcessStores(self::payloadServing(['page-size' => '25', 'theme' => 'dark']));
        $client = $this->initializedClient();
        $updates = self::updatesReceivedBy($client);
        $this->clock->epochMilliseconds += self::FLUSH_INTERVAL_MILLISECONDS + 1;
        $this->anotherProcessStores(self::payloadServing(['theme' => 'dark']));

        $client->getValue('theme', 'light');

        self::assertEquals([new ConfigsUpdatedEvent([], ['page-size'])], $updates->getArrayCopy());
    }

    public function testConfigsUpdatedNamesAConfigTheClientHeldAndCanNoLongerReadAsRemoved(): void
    {
        $this->anotherProcessStores(self::payloadServing(['page-size' => '25', 'theme' => 'dark']));
        $client = $this->initializedClient();
        $updates = self::updatesReceivedBy($client);
        $this->clock->epochMilliseconds += self::FLUSH_INTERVAL_MILLISECONDS + 1;
        $payloadWithAConfigWithoutATarget = self::payloadServing(['theme' => 'dark']);
        $payloadWithAConfigWithoutATarget['configs']['page-size'] = [
            'id' => 'id-of-page-size',
            'key' => 'page-size',
            'type' => 'integer',
        ];
        $this->anotherProcessStores($payloadWithAConfigWithoutATarget);

        $value = $client->getValue('page-size', 10);

        self::assertSame(10, $value);
        self::assertEquals([new ConfigsUpdatedEvent([], ['page-size'])], $updates->getArrayCopy());
    }

    public function testConfigsUpdatedNamesAConfigWhoseRuleUsesASegmentThatChanged(): void
    {
        $this->anotherProcessStores(self::payloadWithASearchEngineForTheSegmentOfThePlan('pro'));
        $client = $this->initializedClient();
        $updates = self::updatesReceivedBy($client);
        $this->clock->epochMilliseconds += self::FLUSH_INTERVAL_MILLISECONDS + 1;
        $this->anotherProcessStores(self::payloadWithASearchEngineForTheSegmentOfThePlan('enterprise'));

        $value = $client->getValue(
            'search-engine',
            'none',
            new Context(id: 'user-1', traits: ['plan' => 'enterprise']),
        );

        self::assertSame('vector', $value);
        self::assertEquals([new ConfigsUpdatedEvent(['search-engine'], [])], $updates->getArrayCopy());
    }

    public function testConfigsUpdatedNamesAConfigWhoseRuleUsesASegmentThatIsGone(): void
    {
        $this->anotherProcessStores(self::payloadWithASearchEngineForTheSegmentOfThePlan('pro'));
        $client = $this->initializedClient();
        $updates = self::updatesReceivedBy($client);
        $this->clock->epochMilliseconds += self::FLUSH_INTERVAL_MILLISECONDS + 1;
        $payloadWithoutTheSegment = self::payloadWithASearchEngineForTheSegmentOfThePlan('pro');
        $payloadWithoutTheSegment['segments'] = [];
        $this->anotherProcessStores($payloadWithoutTheSegment);

        $client->getValue('search-engine', 'none');

        self::assertEquals([new ConfigsUpdatedEvent(['search-engine'], [])], $updates->getArrayCopy());
    }

    public function testConfigsUpdatedNamesAConfigWhoseRuleUsesASegmentThatIsNew(): void
    {
        $payloadWithoutTheSegment = self::payloadWithASearchEngineForTheSegmentOfThePlan('pro');
        $payloadWithoutTheSegment['segments'] = [];
        $this->anotherProcessStores($payloadWithoutTheSegment);
        $client = $this->initializedClient();
        $updates = self::updatesReceivedBy($client);
        $this->clock->epochMilliseconds += self::FLUSH_INTERVAL_MILLISECONDS + 1;
        $this->anotherProcessStores(self::payloadWithASearchEngineForTheSegmentOfThePlan('pro'));

        $client->getValue('search-engine', 'none');

        self::assertEquals([new ConfigsUpdatedEvent(['search-engine'], [])], $updates->getArrayCopy());
    }

    public function testAConfigThatCannotBeReadIsLoggedOnceWhileTheStoredPayloadStaysTheSame(): void
    {
        $payloadWithAConfigWithoutATarget = self::payloadServing(['theme' => 'dark']);
        $payloadWithAConfigWithoutATarget['configs']['page-size'] = [
            'id' => 'id-of-page-size',
            'key' => 'page-size',
            'type' => 'integer',
        ];
        $this->anotherProcessStores($payloadWithAConfigWithoutATarget);
        $client = $this->initializedClient();
        $this->clock->epochMilliseconds += self::FLUSH_INTERVAL_MILLISECONDS + 1;
        $client->isReady();

        $this->clock->epochMilliseconds += 1_000;
        $client->isReady();

        self::assertCount(1, array_column($this->logger->contextsLoggedAt(LogLevel::DEBUG), 'cause'));
    }

    public function testConfigsUpdatedDoesNotFireWhenTheStoredPayloadDefinesEveryConfigAsBefore(): void
    {
        $this->anotherProcessStores(self::payloadServing(['page-size' => '25']));
        $client = $this->initializedClient();
        $updates = self::updatesReceivedBy($client);
        $this->clock->epochMilliseconds += self::FLUSH_INTERVAL_MILLISECONDS + 1;
        $payloadReadLater = self::payloadServing(['page-size' => '25']);
        $payloadReadLater['timestamp'] = '2026-11-12T12:05:00.000000Z';
        $this->anotherProcessStores($payloadReadLater);

        $client->getValue('page-size', 10);

        self::assertSame([], $updates->getArrayCopy());
    }

    public function testAWatchReceivesTheValueAnotherProcessStored(): void
    {
        $this->anotherProcessStores(self::payloadServing(['page-size' => '25']));
        $client = $this->initializedClient();
        $valuesWatched = new \ArrayObject();
        $client->watch('page-size', 10, static function (int $value) use ($valuesWatched): void {
            $valuesWatched->append($value);
        });
        $this->clock->epochMilliseconds += self::FLUSH_INTERVAL_MILLISECONDS + 1;
        $this->anotherProcessStores(self::payloadServing(['page-size' => '50']));

        $client->getValue('page-size', 10);

        self::assertSame([50], $valuesWatched->getArrayCopy());
    }

    public function testAWatchOnAConfigWhoseDefinitionDidNotChangeIsNotCalled(): void
    {
        $this->anotherProcessStores(self::payloadServing(['page-size' => '25', 'theme' => 'dark']));
        $client = $this->initializedClient();
        $valuesWatched = new \ArrayObject();
        $client->watch('theme', 'light', static function (string $value) use ($valuesWatched): void {
            $valuesWatched->append($value);
        });
        $this->clock->epochMilliseconds += self::FLUSH_INTERVAL_MILLISECONDS + 1;
        $this->anotherProcessStores(self::payloadServing(['page-size' => '50', 'theme' => 'dark']));

        $client->getValue('theme', 'light');

        self::assertSame([], $valuesWatched->getArrayCopy());
    }

    public function testAClientThatInitializedWithoutValuesBecomesReadyOnceAnotherProcessFillsTheLocalStore(): void
    {
        $client = $this->initializedClient([['status' => 503]]);
        $timesReady = 0;
        $client->on('clientReady', static function () use (&$timesReady): void {
            $timesReady++;
        });
        $this->clock->epochMilliseconds += self::FLUSH_INTERVAL_MILLISECONDS + 1;
        $this->anotherProcessStores(self::payloadServing(['page-size' => '25']));

        $isReady = $client->isReady();

        self::assertTrue($isReady);
        self::assertSame(1, $timesReady);
        self::assertSame(25, $client->getValue('page-size', 10));
    }

    public function testADisposedClientNoLongerLooksAtTheLocalStore(): void
    {
        $this->anotherProcessStores(self::payloadServing(['page-size' => '25']));
        $client = $this->initializedClient();
        $this->clock->epochMilliseconds += self::FLUSH_INTERVAL_MILLISECONDS + 1;
        $client->dispose();
        $this->anotherProcessStores(self::payloadServing(['page-size' => '50']));

        $this->clock->epochMilliseconds += 1_000;
        $isReady = $client->isReady();

        self::assertFalse($isReady);
    }

    /**
     * @return \ArrayObject<int, ConfigsUpdatedEvent>
     */
    private static function updatesReceivedBy(ConfigDirectorClient $client): \ArrayObject
    {
        /** @var \ArrayObject<int, ConfigsUpdatedEvent> $updates */
        $updates = new \ArrayObject();
        $client->on('configsUpdated', static function (ConfigsUpdatedEvent $update) use ($updates): void {
            $updates->append($update);
        });

        return $updates;
    }

    /**
     * @param array<string, string> $defaultTargetingRuleValuesByConfigKey
     *
     * @return array{
     *     configs: array<string, array<string, mixed>>,
     *     segments: array<string, mixed>,
     *     kind: string,
     *     timestamp: string,
     * }
     */
    private static function payloadServing(array $defaultTargetingRuleValuesByConfigKey): array
    {
        $configs = [];
        foreach ($defaultTargetingRuleValuesByConfigKey as $configKey => $defaultTargetingRuleValue) {
            $configs[$configKey] = [
                'id' => "id-of-{$configKey}",
                'key' => $configKey,
                'type' => 'string',
                'variations' => [],
                'target' => [
                    'rules' => [],
                    'defaultValue' => $defaultTargetingRuleValue,
                    'defaultValueId' => "value-id-of-{$configKey}",
                ],
            ];
        }

        return [
            'configs' => $configs,
            'segments' => [],
            'kind' => 'full',
            'timestamp' => '2026-11-12T12:00:00.000000Z',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function payloadWithASearchEngineForTheSegmentOfThePlan(string $plan): array
    {
        $payload = self::payloadServing(['search-engine' => 'keyword']);
        $payload['configs']['search-engine']['target'] = [
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
                            'segmentId' => 'segment-of-the-plan',
                        ],
                    ],
                ],
            ],
            'defaultValue' => 'keyword',
            'defaultValueId' => 'value-keyword',
        ];
        $payload['segments'] = [
            'segment-of-the-plan' => [
                'groups' => [
                    [
                        [
                            'kind' => 'attribute',
                            'id' => 'condition-plan',
                            'attribute' => 'traits',
                            'trait' => '/plan',
                            'operator' => 'equals',
                            'targetType' => 'text',
                            'targetValues' => [$plan],
                        ],
                    ],
                ],
            ],
        ];

        return $payload;
    }

    /**
     * @param array<array-key, mixed> $payload
     */
    private function anotherProcessStores(array $payload): void
    {
        $refreshLock = LocalStore::ofServerSdkKey('server-sdk-key', $this->storagePath, new RecordingLogger())
            ->refreshLockAcquiredWithin(0);
        self::assertNotNull($refreshLock);
        $refreshLock->replaceStoredPayload(new StoredPayload($payload, $this->clock->epochMilliseconds, 'session-1'));
        $refreshLock->release();
    }

    /**
     * @param list<ScriptedAnswer> $answersToPolls
     */
    private function initializedClient(array $answersToPolls = []): ConfigDirectorClient
    {
        $this->server = ScriptedSdkServer::answering($answersToPolls);
        $logger = $this->logger;
        $transport = new PollingTransport(
            LocalStore::ofServerSdkKey('server-sdk-key', $this->storagePath, $logger),
            new PollingEndpoint($this->server->url, 'server-sdk-key', new Metadata(), SdkIdentity::ofTheServerSdk()),
            PollingInterval::ofConfiguredSeconds(60),
            3_000,
            new ClientLifetime($this->clock, self::FLUSH_INTERVAL_MILLISECONDS),
            $this->clock,
            $logger,
            static function (): void {},
            static function (): void {},
        );
        $client = new DefaultConfigDirectorClient(
            new LocalStoreConnection(static fn(): PollingTransport => $transport, $logger),
            new Metadata(),
            3_000,
            $logger,
            new EventHandlers($logger),
            new DiscardedTelemetry(),
        );
        $client->initialize();

        return $client;
    }
}
