<?php

declare(strict_types=1);

namespace ConfigDirector\Tests\Internal\Client;

use ConfigDirector\ConfigDirector;
use ConfigDirector\ConnectionOptions;
use ConfigDirector\Internal\LocalStore\LocalStore;
use ConfigDirector\Tests\Support\RecordingLogger;
use ConfigDirector\Tests\Support\ScriptedSdkServer;
use ConfigDirector\Tests\Support\TemporaryFolder;
use PHPUnit\Framework\TestCase;

final class ClientPollingIntervalTest extends TestCase
{
    private string $storagePath;

    private ScriptedSdkServer $server;

    #[\Override]
    protected function setUp(): void
    {
        $this->storagePath = TemporaryFolder::create();
        $this->server = ScriptedSdkServer::answering([['status' => 503]]);
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->server->stop();
        TemporaryFolder::remove($this->storagePath);
    }

    public function testAFailedPollPausesPollingForTheConfiguredPollingInterval(): void
    {
        $client = ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            connection: new ConnectionOptions(
                pollingInterval: 600,
                url: $this->server->url,
                storagePath: $this->storagePath,
            ),
            logger: new RecordingLogger(),
        );

        $client->initialize();

        self::assertSame(600_000, $this->millisecondsPollingIsPausedFor());
    }

    public function testAPollingIntervalBelowTheMinimumPausesPollingForSixtySeconds(): void
    {
        $client = ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            connection: new ConnectionOptions(
                pollingInterval: 30,
                url: $this->server->url,
                storagePath: $this->storagePath,
            ),
            logger: new RecordingLogger(),
        );

        $client->initialize();

        self::assertSame(60_000, $this->millisecondsPollingIsPausedFor());
    }

    private function millisecondsPollingIsPausedFor(): int
    {
        $pollingPause = LocalStore::ofServerSdkKey('server-sdk-key', $this->storagePath, new RecordingLogger())
            ->pollingPause($this->server->url . '/server/polling/v1');
        self::assertNotNull($pollingPause);

        return $pollingPause->untilEpochMilliseconds - $pollingPause->recordedAtEpochMilliseconds;
    }
}
