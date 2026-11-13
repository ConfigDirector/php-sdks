<?php

declare(strict_types=1);

namespace ConfigDirector\Tests;

use ConfigDirector\ConfigDirector;
use ConfigDirector\ConfigsUpdatedEvent;
use ConfigDirector\ConnectionOptions;
use ConfigDirector\TelemetryOptions;
use ConfigDirector\Tests\Support\PhpProcess;
use ConfigDirector\Tests\Support\RecordingLogger;
use ConfigDirector\Tests\Support\ScriptedSdkServer;
use ConfigDirector\Tests\Support\TemporaryFolder;
use PHPUnit\Framework\TestCase;

final class ClientLongRunningProcessTest extends TestCase
{
    private string $storagePath;

    private ?ScriptedSdkServer $server = null;

    #[\Override]
    protected function setUp(): void
    {
        $this->storagePath = TemporaryFolder::create();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->server?->stop();
        TemporaryFolder::remove($this->storagePath);
    }

    public function testALongLivedClientServesWithinTwoSecondsTheValueAnotherProcessStored(): void
    {
        $this->server = ScriptedSdkServer::answering([
            ['status' => 200, 'body' => self::payloadServingAPageSizeOf('25')],
        ]);
        $client = ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            connection: new ConnectionOptions(url: $this->server->url, storagePath: $this->storagePath),
            logger: new RecordingLogger(),
            telemetry: new TelemetryOptions(flushInterval: 1),
        );
        $client->initialize();
        $valuesWatched = [];
        $client->watch('page-size', 10, function (int $value) use (&$valuesWatched): void {
            $valuesWatched[] = $value;
        });
        $updates = [];
        $client->on('configsUpdated', function (ConfigsUpdatedEvent $update) use (&$updates): void {
            $updates[] = $update;
        });

        $outputOfTheOtherProcess = PhpProcess::running(
            __DIR__ . '/Support/Processes/store-server-sdk-payload.php',
            [
                $this->storagePath,
                'server-sdk-key',
                json_encode(self::payloadServingAPageSizeOf('50'), JSON_THROW_ON_ERROR),
            ],
        )->finished()->output;
        $storedAt = hrtime(true);
        do {
            usleep(20_000);
            $value = $client->getValue('page-size', 10);
        } while ($value !== 50 && hrtime(true) - $storedAt < 2_000_000_000);

        self::assertSame('stored', $outputOfTheOtherProcess);
        self::assertSame(50, $value);
        self::assertSame([50], $valuesWatched);
        self::assertEquals([new ConfigsUpdatedEvent(['page-size'], [])], $updates);
    }

    public function testAClientYoungerThanTheFlushIntervalKeepsTheValuesItInitializedWith(): void
    {
        $this->server = ScriptedSdkServer::answering([
            ['status' => 200, 'body' => self::payloadServingAPageSizeOf('25')],
        ]);
        $client = ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            connection: new ConnectionOptions(url: $this->server->url, storagePath: $this->storagePath),
            logger: new RecordingLogger(),
        );
        $client->initialize();

        $outputOfTheOtherProcess = PhpProcess::running(
            __DIR__ . '/Support/Processes/store-server-sdk-payload.php',
            [
                $this->storagePath,
                'server-sdk-key',
                json_encode(self::payloadServingAPageSizeOf('50'), JSON_THROW_ON_ERROR),
            ],
        )->finished()->output;
        usleep(1_100_000);
        $value = $client->getValue('page-size', 10);

        self::assertSame('stored', $outputOfTheOtherProcess);
        self::assertSame(25, $value);
    }

    /**
     * @return array<string, mixed>
     */
    private static function payloadServingAPageSizeOf(string $pageSize): array
    {
        return [
            'configs' => [
                'page-size' => [
                    'id' => '1a2b3c4d-5e6f-4a7b-8c9d-0e1f2a3b4c5d',
                    'key' => 'page-size',
                    'type' => 'integer',
                    'variations' => [],
                    'target' => ['rules' => [], 'defaultValue' => $pageSize, 'defaultValueId' => "value-{$pageSize}"],
                ],
            ],
            'segments' => new \stdClass(),
            'kind' => 'full',
            'timestamp' => '2026-11-12T12:00:00.000000Z',
        ];
    }
}
