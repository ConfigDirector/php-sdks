<?php

declare(strict_types=1);

namespace ConfigDirector\Tests;

use ConfigDirector\ConfigDirector;
use ConfigDirector\ConfigDirectorClient;
use ConfigDirector\ConnectionOptions;
use ConfigDirector\Context;
use ConfigDirector\TelemetryOptions;
use ConfigDirector\Tests\Support\ScriptedSdkServer;
use ConfigDirector\Tests\Support\TemporaryFolder;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

/**
 * @phpstan-import-type ScriptedAnswer from ScriptedSdkServer
 */
final class ServerSdkKeySecrecyTest extends TestCase
{
    private const string SERVER_SDK_KEY = '5f2b9c41d7e8a3064b1c9d2e7f80a6b3';

    private const string ANSWER_THAT_REPEATS_THE_REQUEST = '{"serverSdkKey":"5f2b9c41d7e8a3064b1c9d2e7f80a6b3",'
        . '"error":"The request was refused."}';

    private string $storagePath;

    private ?ScriptedSdkServer $server = null;

    /** @var \ArrayObject<int, string> */
    private \ArrayObject $everythingLogged;

    #[\Override]
    protected function setUp(): void
    {
        $this->storagePath = TemporaryFolder::create();
        $this->everythingLogged = new \ArrayObject();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->server?->stop();
        TemporaryFolder::remove($this->storagePath);
    }

    public function testAPollThatFailsWithAnAnswerRepeatingTheKeyLeavesTheKeyOutOfTheLogAndTheLocalStore(): void
    {
        $client = $this->clientOfAServerAnswering([
            ['status' => 500, 'body' => self::ANSWER_THAT_REPEATS_THE_REQUEST],
        ]);

        $client->initialize();
        $client->dispose();

        self::assertSame(1, $this->numberOfLinesLoggedAt(LogLevel::ERROR));
        self::assertSame(0, $this->numberOfTextsHoldingTheKeyAmong($this->everythingLogged->getArrayCopy()));
        self::assertNotSame([], $this->namesAndContentsOfTheFilesOfTheLocalStore());
        self::assertSame(0, $this->numberOfTextsHoldingTheKeyAmong($this->namesAndContentsOfTheFilesOfTheLocalStore()));
    }

    public function testAPollRefusedWithA401RepeatingTheKeyLeavesTheKeyOutOfTheLogTheEventAndTheLocalStore(): void
    {
        $client = $this->clientOfAServerAnswering([
            ['status' => 401, 'body' => self::ANSWER_THAT_REPEATS_THE_REQUEST],
        ]);
        $connectionErrors = [];
        $client->on('connectionError', static function (object $event) use (&$connectionErrors): void {
            $connectionErrors[] = print_r($event, true);
        });

        $client->initialize();
        $client->dispose();

        self::assertCount(1, $connectionErrors);
        self::assertSame(0, $this->numberOfTextsHoldingTheKeyAmong($connectionErrors));
        self::assertSame(1, $this->numberOfLinesLoggedAt(LogLevel::ERROR));
        self::assertSame(0, $this->numberOfTextsHoldingTheKeyAmong($this->everythingLogged->getArrayCopy()));
        self::assertNotSame([], $this->namesAndContentsOfTheFilesOfTheLocalStore());
        self::assertSame(0, $this->numberOfTextsHoldingTheKeyAmong($this->namesAndContentsOfTheFilesOfTheLocalStore()));
    }

    public function testATelemetryReportThatFailsLeavesTheKeyOutOfTheLogAndTheLocalStore(): void
    {
        $client = $this->clientOfAServerAnswering([
            [
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
            ],
            ['status' => 500, 'body' => self::ANSWER_THAT_REPEATS_THE_REQUEST],
        ]);

        $client->initialize();
        $pageSize = $client->getValue('page-size', 10, new Context(id: 'user-42'));
        $client->dispose();
        $filesWhileTheLocalStoreHoldsTheTelemetry = $this->namesAndContentsOfTheFilesOfTheLocalStore();
        $clientOfARequestThatEndsAfterItsFlushInterval = $this->clientWithAFlushIntervalOf(1);
        $clientOfARequestThatEndsAfterItsFlushInterval->initialize();
        usleep(5_000);
        $clientOfARequestThatEndsAfterItsFlushInterval->dispose();

        self::assertSame(25, $pageSize);
        self::assertCount(2, $this->server?->requestsReceived() ?? []);
        self::assertSame(1, $this->numberOfLinesLoggedAt(LogLevel::WARNING));
        self::assertSame(0, $this->numberOfTextsHoldingTheKeyAmong($filesWhileTheLocalStoreHoldsTheTelemetry));
        self::assertSame(0, $this->numberOfTextsHoldingTheKeyAmong($this->everythingLogged->getArrayCopy()));
        self::assertNotSame([], $this->namesAndContentsOfTheFilesOfTheLocalStore());
        self::assertSame(0, $this->numberOfTextsHoldingTheKeyAmong($this->namesAndContentsOfTheFilesOfTheLocalStore()));
    }

    public function testATelemetryReportRefusedWithA401RepeatingTheKeyLeavesTheKeyOutOfTheLogAndTheLocalStore(): void
    {
        $client = $this->clientOfAServerAnswering([
            ['status' => 200, 'body' => ['kind' => 'full', 'configs' => []]],
            ['status' => 401, 'body' => self::ANSWER_THAT_REPEATS_THE_REQUEST],
        ]);

        $client->initialize();
        $client->getValue('page-size', 10, new Context(id: 'user-42'));
        $client->dispose();
        $clientOfARequestThatEndsAfterItsFlushInterval = $this->clientWithAFlushIntervalOf(1);
        $clientOfARequestThatEndsAfterItsFlushInterval->initialize();
        usleep(5_000);
        $clientOfARequestThatEndsAfterItsFlushInterval->dispose();

        self::assertCount(2, $this->server?->requestsReceived() ?? []);
        self::assertSame(1, $this->numberOfLinesLoggedAt(LogLevel::WARNING));
        self::assertSame(0, $this->numberOfTextsHoldingTheKeyAmong($this->everythingLogged->getArrayCopy()));
        self::assertSame(
            1,
            count(array_filter(
                $this->namesAndContentsOfTheFilesOfTheLocalStore(),
                static fn(string $nameOrContents): bool => str_contains($nameOrContents, 'telemetry-pause-'),
            )),
        );
        self::assertSame(0, $this->numberOfTextsHoldingTheKeyAmong($this->namesAndContentsOfTheFilesOfTheLocalStore()));
    }

    public function testTheDefaultLoggerAtDebugLevelWritesNoLineHoldingTheKeyWhenAPollIsRefusedWithA401(): void
    {
        $this->server = ScriptedSdkServer::answering([
            ['status' => 401, 'body' => self::ANSWER_THAT_REPEATS_THE_REQUEST],
        ]);
        $client = ConfigDirector::createClient(
            serverSdkKey: self::SERVER_SDK_KEY,
            connection: new ConnectionOptions(url: $this->server->url, storagePath: $this->storagePath),
            logLevel: LogLevel::DEBUG,
        );

        $this->expectOutputRegex('/\A(?:(?!' . self::SERVER_SDK_KEY . ').)+\z/s');

        $client->initialize();
        $client->getValue('page-size', 10, new Context(id: 'user-42'));
        $client->dispose();
    }

    public function testADumpOfAClientDoesNotShowTheKey(): void
    {
        $client = $this->clientOfAServerAnswering([['status' => 200, 'body' => ['kind' => 'full', 'configs' => []]]]);
        $dumpsBeforeItInitialized = self::dumpsOf($client);

        $client->initialize();
        $dumpsOnceItInitialized = self::dumpsOf($client);
        $client->dispose();

        self::assertSame(0, $this->numberOfTextsHoldingTheKeyAmong($dumpsBeforeItInitialized));
        self::assertSame(0, $this->numberOfTextsHoldingTheKeyAmong($dumpsOnceItInitialized));
    }

    public function testADumpOfAStreamingProcessDoesNotShowTheKey(): void
    {
        $streamingProcess = ConfigDirector::createStreamingProcess(
            serverSdkKey: self::SERVER_SDK_KEY,
            connection: new ConnectionOptions(url: 'http://127.0.0.1:9', storagePath: $this->storagePath),
            logger: $this->loggerKeepingEverythingLogged(),
        );

        $dumps = self::dumpsOf($streamingProcess);

        self::assertSame(0, $this->numberOfTextsHoldingTheKeyAmong($dumps));
    }

    /**
     * @return list<string>
     */
    private static function dumpsOf(object $object): array
    {
        ob_start();
        var_dump($object);

        return [print_r($object, true), var_export($object, true), (string) ob_get_clean()];
    }

    /**
     * @param list<ScriptedAnswer> $answersInOrder
     */
    private function clientOfAServerAnswering(array $answersInOrder): ConfigDirectorClient
    {
        $this->server = ScriptedSdkServer::answering($answersInOrder);

        return $this->clientWithAFlushIntervalOf(60_000);
    }

    private function clientWithAFlushIntervalOf(int $milliseconds): ConfigDirectorClient
    {
        return ConfigDirector::createClient(
            serverSdkKey: self::SERVER_SDK_KEY,
            connection: new ConnectionOptions(url: $this->server->url ?? '', storagePath: $this->storagePath),
            logger: $this->loggerKeepingEverythingLogged(),
            telemetry: new TelemetryOptions(flushInterval: $milliseconds),
        );
    }

    private function loggerKeepingEverythingLogged(): AbstractLogger
    {
        return new class ($this->everythingLogged) extends AbstractLogger {
            /**
             * @param \ArrayObject<int, string> $everythingLogged
             */
            public function __construct(private readonly \ArrayObject $everythingLogged) {}

            /**
             * @param array<array-key, mixed> $context
             */
            #[\Override]
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $levelName = is_string($level) ? $level : '';
                $this->everythingLogged->append("{$levelName}: {$message} " . print_r($context, true));
            }
        };
    }

    private function numberOfLinesLoggedAt(string $level): int
    {
        return count(array_filter(
            $this->everythingLogged->getArrayCopy(),
            static fn(string $line): bool => str_starts_with($line, "{$level}: "),
        ));
    }

    /**
     * @return list<string>
     */
    private function namesAndContentsOfTheFilesOfTheLocalStore(): array
    {
        $namesAndContents = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
            $this->storagePath,
            \FilesystemIterator::SKIP_DOTS,
        ));
        foreach ($files as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                $namesAndContents[] = $file->getPathname();
                $namesAndContents[] = (string) file_get_contents($file->getPathname());
            }
        }

        return $namesAndContents;
    }

    /**
     * @param array<array-key, string> $texts
     */
    private function numberOfTextsHoldingTheKeyAmong(array $texts): int
    {
        return count(array_filter(
            $texts,
            static fn(string $text): bool => str_contains($text, self::SERVER_SDK_KEY),
        ));
    }
}
