<?php

declare(strict_types=1);

namespace ConfigDirector\Tests;

use ConfigDirector\Tests\Support\PhpProcess;
use ConfigDirector\Tests\Support\ScriptedStreamServer;
use ConfigDirector\Tests\Support\TemporaryFolder;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

final class StreamingCommandTest extends TestCase
{
    private const array PAGE_SIZE_OF_25 = [
        'id' => 'config-page-size',
        'key' => 'page-size',
        'type' => 'integer',
        'target' => ['rules' => [], 'defaultValue' => '25', 'defaultValueId' => 'value-25'],
    ];

    private string $storagePath;

    private string $fileOfStandardError;

    private ?ScriptedStreamServer $server = null;

    private ?PhpProcess $runningCommand = null;

    #[\Override]
    protected function setUp(): void
    {
        $this->storagePath = TemporaryFolder::create();
        $this->fileOfStandardError = $this->storagePath . DIRECTORY_SEPARATOR . 'standard-error.txt';
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->runningCommand?->stop();
        $this->server?->stop();
        TemporaryFolder::remove($this->storagePath);
    }

    public function testTheCommandStreamsWithTheKeyTheUrlTheStoragePathAndTheApplicationOfItsEnvironment(): void
    {
        $server = $this->serverStreamingThePageSize();

        $this->startTheCommandWith([
            'CONFIGDIRECTOR_SERVER_KEY' => 'key-of-the-environment',
            'CONFIGDIRECTOR_APP_NAME' => 'checkout',
            'CONFIGDIRECTOR_APP_VERSION' => '4.2.0',
        ]);
        $this->waitUntilTheLocalStoreOfTheKeyHoldsAPayload('key-of-the-environment');

        [$streamRequest] = $server->requestsReceivedAt('/server/sse/v1');
        self::assertIsArray($streamRequest['body']);
        self::assertSame('key-of-the-environment', $streamRequest['body']['serverSdkKey']);
        self::assertIsArray($streamRequest['body']['metaContext']);
        self::assertSame('checkout', $streamRequest['body']['metaContext']['appName']);
        self::assertSame('4.2.0', $streamRequest['body']['metaContext']['appVersion']);
    }

    public function testTheCommandWritesAtInfoLevelByDefaultSoItsFirstLineNamesTheLocalStoreFolder(): void
    {
        $this->serverStreamingThePageSize();

        $this->startTheCommandWith(['CONFIGDIRECTOR_SERVER_KEY' => 'server-sdk-key']);
        $this->waitUntilTheLocalStoreOfTheKeyHoldsAPayload('server-sdk-key');

        self::assertStringContainsString(
            $this->storagePath . DIRECTORY_SEPARATOR . hash('sha256', 'server-sdk-key'),
            (string) file_get_contents($this->fileOfStandardError),
        );
    }

    public function testTheCommandWritesToStandardErrorEvenWhenPhpSendsItsErrorLogToAFile(): void
    {
        $this->serverStreamingThePageSize();
        $errorLogOfPhp = $this->storagePath . DIRECTORY_SEPARATOR . 'php-errors.log';

        $this->runningCommand = $this->commandWith(
            ['CONFIGDIRECTOR_SERVER_KEY' => 'server-sdk-key'],
            ['error_log' => $errorLogOfPhp],
        );
        $this->waitUntilTheLocalStoreOfTheKeyHoldsAPayload('server-sdk-key');

        self::assertStringContainsString(
            $this->storagePath . DIRECTORY_SEPARATOR . hash('sha256', 'server-sdk-key'),
            (string) file_get_contents($this->fileOfStandardError),
        );
        self::assertFileDoesNotExist($errorLogOfPhp);
    }

    public function testASecondCommandOnTheSameLocalStoreExitsWithOne(): void
    {
        $this->serverStreamingThePageSize();
        $this->startTheCommandWith(['CONFIGDIRECTOR_SERVER_KEY' => 'server-sdk-key']);
        $this->waitUntilTheLocalStoreOfTheKeyHoldsAPayload('server-sdk-key');

        $finishedSecondCommand = $this->commandWith(['CONFIGDIRECTOR_SERVER_KEY' => 'server-sdk-key'])->finished();

        self::assertSame(1, $finishedSecondCommand->exitCode);
        self::assertTrue($this->runningCommand?->isRunning());
    }

    public function testTheCommandWritesNothingWhileAllIsWellWhenTheLogLevelIsWarning(): void
    {
        $this->serverStreamingThePageSize();

        $this->startTheCommandWith([
            'CONFIGDIRECTOR_SERVER_KEY' => 'server-sdk-key',
            'CONFIGDIRECTOR_LOG_LEVEL' => 'warning',
        ]);
        $this->waitUntilTheLocalStoreOfTheKeyHoldsAPayload('server-sdk-key');

        self::assertSame('', file_get_contents($this->fileOfStandardError));
    }

    public function testTheCommandExitsWithOneAndConnectsToNothingWhenTheKeyVariableIsBlank(): void
    {
        $server = $this->serverStreamingThePageSize();

        $finishedCommand = $this->commandWith(['CONFIGDIRECTOR_SERVER_KEY' => '  '])->finished();

        self::assertSame(1, $finishedCommand->exitCode);
        self::assertNotSame('', file_get_contents($this->fileOfStandardError));
        self::assertSame([], $server->requestsReceived());
        self::assertSame(['standard-error.txt'], TemporaryFolder::entriesOf($this->storagePath));
    }

    public function testTheCommandExitsWithOneWhenTheLogLevelIsNotAPsr3Level(): void
    {
        $server = $this->serverStreamingThePageSize();

        $finishedCommand = $this->commandWith([
            'CONFIGDIRECTOR_SERVER_KEY' => 'server-sdk-key',
            'CONFIGDIRECTOR_LOG_LEVEL' => 'verbose',
        ])->finished();

        self::assertSame(1, $finishedCommand->exitCode);
        self::assertNotSame('', file_get_contents($this->fileOfStandardError));
        self::assertSame([], $server->requestsReceived());
    }

    public function testTheCommandExitsWithOneWhenTheKeyIsRefused(): void
    {
        $this->server = ScriptedStreamServer::streaming([['status' => 401, 'body' => 'Unauthorized']]);

        $finishedCommand = $this->commandWith(['CONFIGDIRECTOR_SERVER_KEY' => 'server-sdk-key'])->finished();

        self::assertSame(1, $finishedCommand->exitCode);
    }

    #[RequiresPhpExtension('pcntl')]
    public function testTheCommandExitsWithZeroAfterSigterm(): void
    {
        $this->serverStreamingThePageSize();
        $command = $this->commandWith(['CONFIGDIRECTOR_SERVER_KEY' => 'server-sdk-key']);
        $this->waitUntilTheLocalStoreOfTheKeyHoldsAPayload('server-sdk-key');

        $command->signal(SIGTERM);

        self::assertSame(0, $command->finished()->exitCode);
    }

    private function serverStreamingThePageSize(): ScriptedStreamServer
    {
        return $this->server = ScriptedStreamServer::streaming([[
            'chunks' => [ScriptedStreamServer::eventCarrying(
                ['kind' => 'full', 'configs' => ['page-size' => self::PAGE_SIZE_OF_25]],
            )],
        ]]);
    }

    /**
     * @param array<string, string> $settings
     */
    private function startTheCommandWith(array $settings): void
    {
        $this->runningCommand = $this->commandWith($settings);
    }

    /**
     * @param array<string, string> $settings
     * @param array<string, string> $phpSettings
     */
    private function commandWith(array $settings, array $phpSettings = []): PhpProcess
    {
        return PhpProcess::running(
            __DIR__ . '/Processes/run-the-streaming-command.php',
            [],
            phpSettings: $phpSettings,
            environment: [
                'CONFIGDIRECTOR_BASE_URL' => $this->server->url ?? '',
                'CONFIGDIRECTOR_STORAGE_PATH' => $this->storagePath,
                'CONFIGDIRECTOR_APP_NAME' => '',
                'CONFIGDIRECTOR_APP_VERSION' => '',
                'CONFIGDIRECTOR_LOG_LEVEL' => '',
                ...$settings,
            ],
            fileForStandardError: $this->fileOfStandardError,
        );
    }

    private function waitUntilTheLocalStoreOfTheKeyHoldsAPayload(string $serverSdkKey): void
    {
        $payloadFile = implode(
            DIRECTORY_SEPARATOR,
            [$this->storagePath, hash('sha256', $serverSdkKey), 'payload.json'],
        );
        $deadline = hrtime(true) + 5_000_000_000;
        while (!is_file($payloadFile)) {
            if (hrtime(true) > $deadline) {
                self::fail('The local store held no payload within five seconds.');
            }
            usleep(5_000);
            clearstatcache(true, $payloadFile);
        }
    }
}
