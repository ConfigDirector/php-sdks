<?php

declare(strict_types=1);

namespace ConfigDirector\Tests;

use ConfigDirector\ClientHooks;
use ConfigDirector\ConfigDirector;
use ConfigDirector\ConfigDirectorClient;
use ConfigDirector\ConfigDirectorConnectionException;
use ConfigDirector\ConfigEvaluation;
use ConfigDirector\ConfigsUpdatedEvent;
use ConfigDirector\ConnectionErrorEvent;
use ConfigDirector\ConnectionOptions;
use ConfigDirector\EvaluationReason;
use ConfigDirector\Metadata;
use ConfigDirector\Tests\Support\PhpProcess;
use ConfigDirector\Tests\Support\RecordingLogger;
use ConfigDirector\Tests\Support\ScriptedSdkServer;
use ConfigDirector\Tests\Support\TemporaryFolder;
use ConfigDirector\Version;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

/**
 * @phpstan-import-type ScriptedAnswer from ScriptedSdkServer
 */
final class ClientInitializationTest extends TestCase
{
    private const array PAYLOAD_OF_ONE_CONFIG = [
        'configs' => [
            'page-size' => [
                'id' => '1a2b3c4d-5e6f-4a7b-8c9d-0e1f2a3b4c5d',
                'key' => 'page-size',
                'type' => 'integer',
                'target' => ['rules' => [], 'defaultValue' => '25', 'defaultValueId' => 'value-25'],
            ],
        ],
        'kind' => 'full',
    ];

    private string $storagePath;

    /** @var list<ScriptedSdkServer> */
    private array $servers = [];

    #[\Override]
    protected function setUp(): void
    {
        $this->storagePath = TemporaryFolder::create();
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->servers as $server) {
            $server->stop();
        }
        TemporaryFolder::remove($this->storagePath);
    }

    public function testCreatingAClientSendsNoPollAndWritesNothingToTheStoragePath(): void
    {
        $server = $this->serverAnswering([['status' => 200, 'body' => self::PAYLOAD_OF_ONE_CONFIG]]);

        $this->clientPolling($server, new RecordingLogger());

        self::assertSame([], $server->requestsReceived());
        self::assertSame([], TemporaryFolder::entriesOf($this->storagePath));
    }

    public function testInitializeOnAnEmptyLocalStoreSendsOnePollAndServesWhatItReceived(): void
    {
        $server = $this->serverAnswering([['status' => 200, 'body' => self::PAYLOAD_OF_ONE_CONFIG]]);
        $client = $this->clientPolling($server, new RecordingLogger());

        $client->initialize();

        self::assertCount(1, $server->requestsReceived());
        self::assertTrue($client->isReady());
        self::assertSame(25, $client->getValue('page-size', 10));
    }

    public function testThePollNamesTheServerSdkAndItsVersion(): void
    {
        $server = $this->serverAnswering([['status' => 200, 'body' => self::PAYLOAD_OF_ONE_CONFIG]]);
        $client = $this->clientPolling($server, new RecordingLogger());

        $client->initialize();

        $request = $server->requestsReceived()[0];
        self::assertIsArray($request['body']);
        self::assertSame(
            ['sdkName' => 'php-server-sdk', 'sdkVersion' => Version::CURRENT],
            $request['body']['metaContext'] ?? null,
        );
        self::assertSame('php-server-sdk/' . Version::CURRENT, $request['headers']['user-agent']);
    }

    public function testThePollCarriesTheServerSdkKeyAndTheMetadataOfTheApplication(): void
    {
        $server = $this->serverAnswering([['status' => 200, 'body' => self::PAYLOAD_OF_ONE_CONFIG]]);
        $client = ConfigDirector::createClient(
            serverSdkKey: 'key-of-the-checkout-environment',
            metadata: new Metadata(appName: 'checkout-api', appVersion: '4.2.0'),
            connection: new ConnectionOptions(url: $server->url, storagePath: $this->storagePath),
            logger: new RecordingLogger(),
        );

        $client->initialize();

        $body = $server->requestsReceived()[0]['body'];
        self::assertIsArray($body);
        self::assertSame('key-of-the-checkout-environment', $body['serverSdkKey'] ?? null);
        self::assertIsArray($body['metaContext'] ?? null);
        self::assertSame('checkout-api', $body['metaContext']['appName'] ?? null);
        self::assertSame('4.2.0', $body['metaContext']['appVersion'] ?? null);
    }

    public function testAnEnvironmentWithoutConfigsMakesTheClientReadyAndFiresBothEvents(): void
    {
        $server = $this->serverAnswering([
            ['status' => 200, 'body' => ['configs' => new \stdClass(), 'kind' => 'full']],
        ]);
        $client = $this->clientPolling($server, new RecordingLogger());
        $eventsFired = [];
        $client->on('configsUpdated', function (ConfigsUpdatedEvent $update) use (&$eventsFired): void {
            $eventsFired[] = $update;
        });
        $client->on('clientReady', function () use (&$eventsFired): void {
            $eventsFired[] = 'clientReady';
        });

        $client->initialize();

        self::assertTrue($client->isReady());
        self::assertEquals([new ConfigsUpdatedEvent([], []), 'clientReady'], $eventsFired);
    }

    public function testASecondClientOfTheHostServesTheStoredValuesWithoutPolling(): void
    {
        $server = $this->serverAnswering([['status' => 200, 'body' => self::PAYLOAD_OF_ONE_CONFIG]]);
        $this->clientPolling($server, new RecordingLogger())->initialize();
        $secondClient = $this->clientPolling($server, new RecordingLogger());

        $secondClient->initialize();

        self::assertCount(1, $server->requestsReceived());
        self::assertSame(25, $secondClient->getValue('page-size', 10));
    }

    public function testAClientWhosePollIsRefusedIsNotReadyAndReturnsInCodeDefaultValues(): void
    {
        $server = $this->serverAnswering([['status' => 503]]);
        $client = $this->clientPolling($server, new RecordingLogger());

        $client->initialize();

        self::assertFalse($client->isReady());
        self::assertEquals(
            new ConfigEvaluation('page-size', 10, null, null, true, EvaluationReason::ClientNotReady, null),
            $client->evaluate('page-size', 10),
        );
    }

    public function testARefusedPollLogsOneErrorWithItsStatusAndNoWarning(): void
    {
        $server = $this->serverAnswering([['status' => 503]]);
        $logger = new RecordingLogger();
        $client = $this->clientPolling($server, $logger);

        $client->initialize();

        $errors = $logger->contextsLoggedAt(LogLevel::ERROR);
        self::assertCount(1, $errors);
        self::assertInstanceOf(ConfigDirectorConnectionException::class, $errors[0]['exception'] ?? null);
        self::assertSame(503, $errors[0]['exception']->status);
        self::assertSame([], $logger->contextsLoggedAt(LogLevel::WARNING));
    }

    public function testInitializeLogsOneWarningWithTheTimeoutWhenAnotherProcessPollsPastIt(): void
    {
        $server = $this->serverAnswering([['status' => 200, 'body' => self::PAYLOAD_OF_ONE_CONFIG]]);
        $logger = new RecordingLogger();
        $client = $this->clientPolling($server, $logger, timeout: 200);
        $processThatPolls = PhpProcess::running(
            __DIR__ . '/Internal/LocalStore/Processes/keep-the-refresh-lock.php',
            [$this->storagePath, 'server-sdk-key', '600'],
        );
        $lineOfTheProcessThatPolls = $processThatPolls->nextLineOfOutput();

        $client->initialize();
        $processThatPolls->finished();

        self::assertSame('locked', $lineOfTheProcessThatPolls);
        self::assertFalse($client->isReady());
        self::assertSame([['timeout' => 200]], $logger->contextsLoggedAt(LogLevel::WARNING));
        self::assertSame([], $logger->contextsLoggedAt(LogLevel::ERROR));
    }

    public function testARequestThatFindsAFailedPollOnRecordDoesNotPoll(): void
    {
        $server = $this->serverAnswering([['status' => 503], ['status' => 200, 'body' => self::PAYLOAD_OF_ONE_CONFIG]]);
        $this->clientPolling($server, new RecordingLogger())->initialize();
        $clientOfTheNextRequest = $this->clientPolling($server, new RecordingLogger());

        $clientOfTheNextRequest->initialize();

        self::assertCount(1, $server->requestsReceived());
        self::assertFalse($clientOfTheNextRequest->isReady());
    }

    public function testARequestThatFindsAFailedPollOnRecordLogsOneDebugLineAndNoWarningOrError(): void
    {
        $server = $this->serverAnswering([['status' => 503]]);
        $this->clientPolling($server, new RecordingLogger())->initialize();
        $loggerOfTheNextRequest = new RecordingLogger();
        $clientOfTheNextRequest = $this->clientPolling($server, $loggerOfTheNextRequest);

        $clientOfTheNextRequest->initialize();

        self::assertSame([], $loggerOfTheNextRequest->contextsLoggedAt(LogLevel::WARNING));
        self::assertSame([], $loggerOfTheNextRequest->contextsLoggedAt(LogLevel::ERROR));
        self::assertCount(1, $loggerOfTheNextRequest->contextsLoggedAt(LogLevel::DEBUG));
    }

    public function testAStoragePathThatCannotBeUsedLogsOneErrorNamingTheFolderAndNoWarning(): void
    {
        $server = $this->serverAnswering([['status' => 200, 'body' => self::PAYLOAD_OF_ONE_CONFIG]]);
        $regularFile = $this->storagePath . DIRECTORY_SEPARATOR . 'regular-file';
        touch($regularFile);
        $logger = new RecordingLogger();
        $client = ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            connection: new ConnectionOptions(url: $server->url, storagePath: $regularFile),
            logger: $logger,
        );

        $client->initialize();

        $errors = $logger->contextsLoggedAt(LogLevel::ERROR);
        self::assertCount(1, $errors);
        self::assertIsString($errors[0]['folder'] ?? null);
        self::assertStringStartsWith($regularFile, $errors[0]['folder']);
        self::assertSame([], $logger->contextsLoggedAt(LogLevel::WARNING));
        self::assertSame([], $server->requestsReceived());
        self::assertFalse($client->isReady());
    }

    public function testAPollRefusedWithA401FiresConnectionErrorWithTheStatus(): void
    {
        $server = $this->serverAnswering([['status' => 401, 'body' => 'Unauthorized']]);
        $statusesOfConnectionErrors = [];
        $client = ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            connection: new ConnectionOptions(url: $server->url, storagePath: $this->storagePath),
            logger: new RecordingLogger(),
            hooks: new ClientHooks(
                connectionError: function (ConnectionErrorEvent $event) use (&$statusesOfConnectionErrors): void {
                    $statusesOfConnectionErrors[] = $event->error instanceof ConfigDirectorConnectionException
                        ? $event->error->status
                        : null;
                },
            ),
        );

        $client->initialize();

        self::assertSame([401], $statusesOfConnectionErrors);
    }

    public function testAPollRefusedWithA503FiresNoConnectionError(): void
    {
        $server = $this->serverAnswering([['status' => 503]]);
        $connectionErrors = 0;
        $client = $this->clientPolling($server, new RecordingLogger());
        $client->on('connectionError', function () use (&$connectionErrors): void {
            $connectionErrors++;
        });

        $client->initialize();

        self::assertSame(0, $connectionErrors);
    }

    /**
     * @param list<ScriptedAnswer> $answersInOrder
     */
    private function serverAnswering(array $answersInOrder): ScriptedSdkServer
    {
        $server = ScriptedSdkServer::answering($answersInOrder);
        $this->servers[] = $server;

        return $server;
    }

    private function clientPolling(
        ScriptedSdkServer $server,
        RecordingLogger $logger,
        int $timeout = 3_000,
    ): ConfigDirectorClient {
        return ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            connection: new ConnectionOptions(timeout: $timeout, url: $server->url, storagePath: $this->storagePath),
            logger: $logger,
        );
    }
}
