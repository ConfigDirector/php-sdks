<?php

declare(strict_types=1);

namespace ConfigDirector\Tests\Internal\Connection;

use ConfigDirector\Internal\Connection\LocalStoreConnection;
use ConfigDirector\Internal\Connection\ServerSdkPayload;
use ConfigDirector\Internal\Identity\SdkIdentity;
use ConfigDirector\Internal\LocalStore\LocalStore;
use ConfigDirector\Internal\LocalStore\StoredPayload;
use ConfigDirector\Internal\Polling\PollingEndpoint;
use ConfigDirector\Internal\Polling\PollingInterval;
use ConfigDirector\Internal\Polling\PollingTransport;
use ConfigDirector\Metadata;
use ConfigDirector\Tests\Support\RecordingLogger;
use ConfigDirector\Tests\Support\SettableClock;
use ConfigDirector\Tests\Support\TemporaryFolder;
use PHPUnit\Framework\TestCase;

final class LocalStoreConnectionTest extends TestCase
{
    private const int NOVEMBER_12_2026_AT_NOON = 1_794_484_800_000;

    private string $storagePath;

    private SettableClock $clock;

    #[\Override]
    protected function setUp(): void
    {
        $this->storagePath = TemporaryFolder::create();
        $this->clock = new SettableClock(self::NOVEMBER_12_2026_AT_NOON);
    }

    #[\Override]
    protected function tearDown(): void
    {
        TemporaryFolder::remove($this->storagePath);
    }

    public function testAReadFindsNoPayloadWhileTheLocalStoreHoldsTheOneAlreadyDelivered(): void
    {
        $this->storePayload(['configs' => [], 'timestamp' => '2026-11-12T12:00:00.000000Z']);
        $connection = $this->connectionOfALongRunningProcess();
        $connection->payloadForInitialization();

        $this->clock->epochMilliseconds += 30_001;
        $payloadFound = $connection->payloadFoundByARead();

        self::assertNull($payloadFound);
    }

    public function testAReadFindsThePayloadStoredSinceTheOneDelivered(): void
    {
        $this->storePayload(['configs' => [], 'timestamp' => '2026-11-12T12:00:00.000000Z']);
        $connection = $this->connectionOfALongRunningProcess();
        $connection->payloadForInitialization();
        $this->clock->epochMilliseconds += 30_001;
        $this->storePayload(['configs' => [], 'timestamp' => '2026-11-12T12:00:30.000000Z']);

        $payloadFound = $connection->payloadFoundByARead();

        self::assertInstanceOf(ServerSdkPayload::class, $payloadFound);
    }

    /**
     * @param array<array-key, mixed> $payload
     */
    private function storePayload(array $payload): void
    {
        $refreshLock = LocalStore::ofServerSdkKey('server-sdk-key', $this->storagePath, new RecordingLogger())
            ->refreshLockAcquiredWithin(0);
        self::assertNotNull($refreshLock);
        $refreshLock->replaceStoredPayload(new StoredPayload($payload, $this->clock->epochMilliseconds, 'session-1'));
        $refreshLock->release();
    }

    private function connectionOfALongRunningProcess(): LocalStoreConnection
    {
        $logger = new RecordingLogger();

        return new LocalStoreConnection(
            fn(\Closure $reportPayloadReceivedByAPoll): PollingTransport => new PollingTransport(
                LocalStore::ofServerSdkKey('server-sdk-key', $this->storagePath, $logger),
                new PollingEndpoint(
                    'http://127.0.0.1:9',
                    'server-sdk-key',
                    new Metadata(),
                    SdkIdentity::ofTheServerSdk(),
                ),
                PollingInterval::ofConfiguredSeconds(60),
                3_000,
                30_000,
                $this->clock,
                $logger,
                static function (): void {},
                $reportPayloadReceivedByAPoll,
            ),
            $logger,
        );
    }
}
