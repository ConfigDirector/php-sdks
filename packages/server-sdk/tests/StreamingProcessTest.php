<?php

declare(strict_types=1);

namespace ConfigDirector\Tests;

use ConfigDirector\ConfigDirector;
use ConfigDirector\ConfigDirectorConnectionException;
use ConfigDirector\ConnectionOptions;
use ConfigDirector\Tests\Support\FinishedPhpProcess;
use ConfigDirector\Tests\Support\PhpProcess;
use ConfigDirector\Tests\Support\ScriptedStreamServer;
use ConfigDirector\Tests\Support\TemporaryFolder;
use ConfigDirector\Version;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @phpstan-import-type ScriptedStream from ScriptedStreamServer
 */
final class StreamingProcessTest extends TestCase
{
    private const array PAGE_SIZE_OF_25 = [
        'id' => 'config-page-size',
        'key' => 'page-size',
        'type' => 'integer',
        'target' => ['rules' => [], 'defaultValue' => '25', 'defaultValueId' => 'value-25'],
    ];

    private const array PAGE_SIZE_OF_50 = [
        'id' => 'config-page-size',
        'key' => 'page-size',
        'type' => 'integer',
        'target' => ['rules' => [], 'defaultValue' => '50', 'defaultValueId' => 'value-50'],
    ];

    private const string UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    private string $storagePath;

    private ?ScriptedStreamServer $server = null;

    /** @var list<PhpProcess> */
    private array $streamingProcesses = [];

    #[\Override]
    protected function setUp(): void
    {
        $this->storagePath = TemporaryFolder::create();
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->streamingProcesses as $streamingProcess) {
            $streamingProcess->stop();
        }
        $this->server?->stop();
        TemporaryFolder::remove($this->storagePath);
    }

    public function testTheFirstPayloadOfTheStreamFillsAnEmptyLocalStoreSoARequestReadsItWithoutPolling(): void
    {
        $server = $this->serverStreaming([
            ['chunks' => [ScriptedStreamServer::eventCarrying(self::fullPayloadOf(self::PAGE_SIZE_OF_25))]],
        ]);
        $this->startAStreamingProcess();
        $this->waitUntilTheLocalStoreHoldsAPayload();

        $outputOfARequest = $this->requestThatReadsThePageSize()->finished()->output;

        self::assertSame("page-size: 25\n", $outputOfARequest);
        self::assertSame([], $server->requestsReceivedAt('/server/polling/v1'));
    }

    public function testAChangePushedThroughTheStreamIsReadByARequestInAnotherProcessWithinTwoSeconds(): void
    {
        $server = $this->serverStreaming([
            ['chunks' => [ScriptedStreamServer::eventCarrying(self::fullPayloadOf(self::PAGE_SIZE_OF_25))]],
        ]);
        $this->startAStreamingProcess();
        $this->waitUntilTheLocalStoreHoldsAPayload();
        $outputBeforeTheChange = $this->requestThatReadsThePageSize()->finished()->output;

        $server->sendToTheOpenStream(ScriptedStreamServer::eventCarrying(
            ['kind' => 'delta', 'configs' => ['page-size' => self::PAGE_SIZE_OF_50]],
        ));
        $changePushedAt = hrtime(true);
        do {
            $outputAfterTheChange = $this->requestThatReadsThePageSize()->finished()->output;
            $millisecondsSinceTheChange = intdiv(hrtime(true) - $changePushedAt, 1_000_000);
        } while ($outputAfterTheChange !== "page-size: 50\n" && $millisecondsSinceTheChange < 2_000);

        self::assertSame("page-size: 25\n", $outputBeforeTheChange);
        self::assertSame("page-size: 50\n", $outputAfterTheChange);
        self::assertSame([], $server->requestsReceivedAt('/server/polling/v1'));
    }

    public function testTheStreamIsOpenedWithAPostThatCarriesTheKeyANewSessionIdAndTheMetadata(): void
    {
        $server = $this->serverStreaming([
            ['chunks' => [ScriptedStreamServer::eventCarrying(self::fullPayloadOf(self::PAGE_SIZE_OF_25))]],
        ]);
        $this->startAStreamingProcess();
        $this->waitUntilTheLocalStoreHoldsAPayload();

        [$streamRequest] = $server->requestsReceivedAt('/server/sse/v1');

        self::assertSame('POST', $streamRequest['method']);
        self::assertSame('application/json', $streamRequest['headers']['content-type']);
        self::assertSame('text/event-stream', $streamRequest['headers']['accept']);
        self::assertSame('php-server-sdk/' . Version::CURRENT, $streamRequest['headers']['user-agent']);
        self::assertArrayNotHasKey('last-event-id', $streamRequest['headers']);
        self::assertIsArray($streamRequest['body']);
        self::assertSame(['serverSdkKey', 'sessionId', 'metaContext'], array_keys($streamRequest['body']));
        self::assertSame('server-sdk-key', $streamRequest['body']['serverSdkKey']);
        self::assertIsString($streamRequest['body']['sessionId']);
        self::assertMatchesRegularExpression(self::UUID_PATTERN, $streamRequest['body']['sessionId']);
        self::assertSame(
            [
                'sdkName' => 'php-server-sdk',
                'sdkVersion' => Version::CURRENT,
                'appName' => 'checkout',
                'appVersion' => '4.2.0',
            ],
            $streamRequest['body']['metaContext'],
        );
    }

    public function testADroppedStreamIsOpenedAgainWithANewSessionIdAndTheLastEventId(): void
    {
        $server = $this->serverStreaming([
            [
                'chunks' => [
                    "id: 7\n" . ScriptedStreamServer::eventCarrying(self::fullPayloadOf(self::PAGE_SIZE_OF_25)),
                ],
                'closes' => true,
            ],
            ['chunks' => [ScriptedStreamServer::eventCarrying(self::fullPayloadOf(self::PAGE_SIZE_OF_50))]],
        ]);
        $this->startAStreamingProcess();

        self::waitUntil(static fn(): bool => count($server->requestsReceivedAt('/server/sse/v1')) === 2);
        [$firstStreamRequest, $secondStreamRequest] = $server->requestsReceivedAt('/server/sse/v1');

        self::assertSame('7', $secondStreamRequest['headers']['last-event-id']);
        self::assertIsArray($firstStreamRequest['body']);
        self::assertIsArray($secondStreamRequest['body']);
        self::assertIsString($secondStreamRequest['body']['sessionId']);
        self::assertMatchesRegularExpression(self::UUID_PATTERN, $secondStreamRequest['body']['sessionId']);
        self::assertNotSame($firstStreamRequest['body']['sessionId'], $secondStreamRequest['body']['sessionId']);
    }

    public function testAStreamRefusedWithA401MakesRunThrowAfterOneErrorAndIsNotOpenedAgain(): void
    {
        $server = $this->serverStreaming([['status' => 401, 'body' => 'Unauthorized']]);

        $output = $this->finished($this->startAStreamingProcess())->output;

        self::assertSame(
            ['info-logged', 'error-logged', 'run-threw-connection-exception 401'],
            self::linesOf($output),
        );
        self::assertCount(1, $server->requestsReceivedAt('/server/sse/v1'));
    }

    public function testASecondStreamingProcessOnTheSameLocalStoreThrowsAfterOneErrorAndTheFirstKeepsRunning(): void
    {
        $server = $this->serverStreaming([
            ['chunks' => [ScriptedStreamServer::eventCarrying(self::fullPayloadOf(self::PAGE_SIZE_OF_25))]],
        ]);
        $firstStreamingProcess = $this->startAStreamingProcess();
        $this->waitUntilTheLocalStoreHoldsAPayload();

        $outputOfTheSecond = $this->finished($this->startAStreamingProcess())->output;

        self::assertSame(['error-logged', 'run-threw-configdirector-exception'], self::linesOf($outputOfTheSecond));
        self::assertTrue($firstStreamingProcess->isRunning());
        self::assertCount(1, $server->requestsReceivedAt('/server/sse/v1'));
    }

    public function testAStreamingProcessWhoseLocalStoreCannotBeUsedThrowsAfterOneErrorAndNeverConnects(): void
    {
        $server = $this->serverStreaming([]);
        $regularFile = $this->storagePath . DIRECTORY_SEPARATOR . 'a-regular-file';
        touch($regularFile);

        $output = PhpProcess::running(
            __DIR__ . '/Processes/run-a-streaming-process.php',
            [$regularFile . DIRECTORY_SEPARATOR . 'inside-it', $server->url, '30000'],
        )->finished()->output;

        self::assertSame(['error-logged', 'run-threw-configdirector-exception'], self::linesOf($output));
        self::assertSame([], $server->requestsReceived());
    }

    #[RequiresPhpExtension('pcntl')]
    public function testSigtermSendsTheTelemetryTheLocalStoreHoldsThenRunReturnsAndTheProcessExitsWithZero(): void
    {
        $server = $this->serverStreaming([
            ['chunks' => [ScriptedStreamServer::eventCarrying(self::fullPayloadOf(self::PAGE_SIZE_OF_25))]],
        ]);
        $streamingProcess = $this->startAStreamingProcess();
        $this->waitUntilTheLocalStoreHoldsAPayload();
        $this->requestThatReadsThePageSize()->finished();
        $reportsSentBeforeTheSignal = $server->requestsReceivedAt('/server/telemetry/v1');

        $streamingProcess->signal(SIGTERM);
        $finishedProcess = $this->finished($streamingProcess);
        $reports = $server->requestsReceivedAt('/server/telemetry/v1');

        self::assertSame([], $reportsSentBeforeTheSignal);
        self::assertSame(['info-logged', 'info-logged', 'run-returned'], self::linesOf($finishedProcess->output));
        self::assertSame(0, $finishedProcess->exitCode);
        self::assertCount(1, $reports);
        self::assertIsArray($reports[0]['body']);
        self::assertIsArray($reports[0]['body']['aggregatedEvents']);
        self::assertIsArray($reports[0]['body']['aggregatedEvents']['evaluatedConfig']);
        self::assertSame(
            ['page-size'],
            array_column(array_column($reports[0]['body']['aggregatedEvents']['evaluatedConfig'], 'event'), 'key'),
        );
    }

    #[RequiresPhpExtension('pcntl')]
    public function testSigintStopsTheStreamingProcessAsSigtermDoes(): void
    {
        $this->serverStreaming([
            ['chunks' => [ScriptedStreamServer::eventCarrying(self::fullPayloadOf(self::PAGE_SIZE_OF_25))]],
        ]);
        $streamingProcess = $this->startAStreamingProcess();
        $this->waitUntilTheLocalStoreHoldsAPayload();

        $streamingProcess->signal(SIGINT);
        $finishedProcess = $this->finished($streamingProcess);

        self::assertSame(['info-logged', 'info-logged', 'run-returned'], self::linesOf($finishedProcess->output));
        self::assertSame(0, $finishedProcess->exitCode);
    }

    #[RequiresPhpExtension('pcntl')]
    public function testAStreamingProcessStartsOnALocalStoreWhoseStreamingProcessWasKilled(): void
    {
        $server = $this->serverStreaming([
            ['chunks' => [ScriptedStreamServer::eventCarrying(self::fullPayloadOf(self::PAGE_SIZE_OF_25))]],
            ['chunks' => [ScriptedStreamServer::eventCarrying(self::fullPayloadOf(self::PAGE_SIZE_OF_50))]],
        ]);
        $killedStreamingProcess = $this->startAStreamingProcess();
        $this->waitUntilTheLocalStoreHoldsAPayload();
        $killedStreamingProcess->signal(SIGKILL);
        $this->finished($killedStreamingProcess);

        $nextStreamingProcess = $this->startAStreamingProcess();
        self::waitUntil(static fn(): bool => count($server->requestsReceivedAt('/server/sse/v1')) === 2);
        self::waitUntil(fn(): bool => str_contains($this->textOfTheStoredPayload(), '"defaultValue":"50"'));
        $outputOfARequest = $this->requestThatReadsThePageSize()->finished()->output;

        self::assertTrue($nextStreamingProcess->isRunning());
        self::assertSame("page-size: 50\n", $outputOfARequest);
    }

    public function testARequestInAnotherProcessThatFindsAReportDueLeavesItToTheRunningStreamingProcess(): void
    {
        $server = $this->serverStreaming([
            ['chunks' => [ScriptedStreamServer::eventCarrying(self::fullPayloadOf(self::PAGE_SIZE_OF_25))]],
        ]);
        $this->startAStreamingProcess();
        $this->waitUntilTheLocalStoreHoldsAPayload();

        $outputOfTheRequest = $this->outputOfARequestThatEndsAfterItsFlushIntervalOfOneMillisecond();

        self::assertSame("page-size: 25\n", $outputOfTheRequest);
        self::assertSame([], $server->requestsReceivedAt('/server/telemetry/v1'));
    }

    public function testARequestInAnotherProcessThatFindsAReportDueSendsItOnceTheStreamingProcessIsGone(): void
    {
        $server = $this->serverStreaming([
            ['chunks' => [ScriptedStreamServer::eventCarrying(self::fullPayloadOf(self::PAGE_SIZE_OF_25))]],
        ]);
        $streamingProcess = $this->startAStreamingProcess();
        $this->waitUntilTheLocalStoreHoldsAPayload();
        $this->outputOfARequestThatEndsAfterItsFlushIntervalOfOneMillisecond();
        $this->streamingProcesses = [];
        $streamingProcess->stop();
        $reportsSentByTheTimeItWasGone = count($server->requestsReceivedAt('/server/telemetry/v1'));

        $this->outputOfARequestThatEndsAfterItsFlushIntervalOfOneMillisecond();

        $reports = $server->requestsReceivedAt('/server/telemetry/v1');
        self::assertCount($reportsSentByTheTimeItWasGone + 1, $reports);
        $reportOfTheRequest = $reports[$reportsSentByTheTimeItWasGone];
        self::assertIsArray($reportOfTheRequest['body']);
        self::assertIsArray($reportOfTheRequest['body']['metaContext']);
        self::assertArrayNotHasKey('appName', $reportOfTheRequest['body']['metaContext']);
    }

    #[RequiresPhpExtension('pcntl')]
    public function testRunPutsBackTheSignalHandlersThatWereThereBeforeIt(): void
    {
        $server = $this->serverStreaming([['status' => 401, 'body' => 'Unauthorized']]);
        $handlerOfTheApplication = static function (): void {};
        pcntl_signal(SIGTERM, $handlerOfTheApplication);
        $streamingProcess = ConfigDirector::createStreamingProcess(
            serverSdkKey: 'server-sdk-key',
            connection: new ConnectionOptions(url: $server->url, storagePath: $this->storagePath),
            logger: new NullLogger(),
        );

        try {
            $streamingProcess->run();
            self::fail('A refused server SDK key did not make run() throw.');
        } catch (ConfigDirectorConnectionException) {
            $sigtermHandlerAfterTheRun = pcntl_signal_get_handler(SIGTERM);
            $sigintHandlerAfterTheRun = pcntl_signal_get_handler(SIGINT);
        } finally {
            pcntl_signal(SIGTERM, SIG_DFL);
        }

        self::assertSame($handlerOfTheApplication, $sigtermHandlerAfterTheRun);
        self::assertSame(SIG_DFL, $sigintHandlerAfterTheRun);
    }

    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function testAStreamingProcessStoppedByASignalRunsAgainUntilTheNextSignal(): void
    {
        $server = $this->serverStreaming([
            ['chunks' => [ScriptedStreamServer::eventCarrying(self::fullPayloadOf(self::PAGE_SIZE_OF_25))]],
            ['chunks' => [ScriptedStreamServer::eventCarrying(self::fullPayloadOf(self::PAGE_SIZE_OF_50))]],
        ]);
        $streamingProcess = ConfigDirector::createStreamingProcess(
            serverSdkKey: 'server-sdk-key',
            connection: new ConnectionOptions(url: $server->url, storagePath: $this->storagePath),
            logger: new NullLogger(),
        );
        pcntl_signal(SIGALRM, static function (): void {
            posix_kill(posix_getpid(), SIGTERM);
        });

        try {
            pcntl_alarm(1);
            $streamingProcess->run();
            $streamsOpenedByTheFirstRun = count($server->requestsReceivedAt('/server/sse/v1'));
            pcntl_alarm(1);
            $streamingProcess->run();
        } finally {
            pcntl_alarm(0);
            pcntl_signal(SIGALRM, SIG_DFL);
        }

        self::assertSame(1, $streamsOpenedByTheFirstRun);
        self::assertCount(2, $server->requestsReceivedAt('/server/sse/v1'));
    }

    private function finished(PhpProcess $streamingProcess): FinishedPhpProcess
    {
        $this->streamingProcesses = array_values(array_filter(
            $this->streamingProcesses,
            static fn(PhpProcess $runningProcess): bool => $runningProcess !== $streamingProcess,
        ));

        return $streamingProcess->finished();
    }

    /**
     * @return list<string>
     */
    private static function linesOf(string $output): array
    {
        return array_values(array_filter(explode("\n", $output), static fn(string $line): bool => $line !== ''));
    }

    /**
     * @param list<ScriptedStream> $streamsInOrder
     */
    private function serverStreaming(array $streamsInOrder): ScriptedStreamServer
    {
        return $this->server = ScriptedStreamServer::streaming($streamsInOrder);
    }

    private function startAStreamingProcess(): PhpProcess
    {
        return $this->streamingProcesses[] = PhpProcess::running(
            __DIR__ . '/Processes/run-a-streaming-process.php',
            [$this->storagePath, $this->server->url ?? '', '30000'],
        );
    }

    private function outputOfARequestThatEndsAfterItsFlushIntervalOfOneMillisecond(): string
    {
        $fileAwaitedBeforeEnding = $this->storagePath . DIRECTORY_SEPARATOR . 'end-' . bin2hex(random_bytes(4));
        $request = PhpProcess::running(
            __DIR__ . '/Processes/serve-a-request-that-reads-a-config.php',
            [$this->storagePath, $this->server->url ?? '', '1', $fileAwaitedBeforeEnding],
        );
        $firstLineOfOutput = $request->nextLineOfOutput();
        usleep(5_000);
        touch($fileAwaitedBeforeEnding);

        return $firstLineOfOutput . "\n" . $request->finished()->output;
    }

    private function textOfTheStoredPayload(): string
    {
        $payloadFiles = glob($this->storagePath . '/*/payload.json');

        return $payloadFiles === false || $payloadFiles === [] ? '' : (string) file_get_contents($payloadFiles[0]);
    }

    private function requestThatReadsThePageSize(): PhpProcess
    {
        return PhpProcess::running(
            __DIR__ . '/Processes/serve-a-request-that-reads-a-config.php',
            [$this->storagePath, $this->server->url ?? '', '30000', ''],
        );
    }

    private function waitUntilTheLocalStoreHoldsAPayload(): void
    {
        self::waitUntil(fn(): bool => glob($this->storagePath . '/*/payload.json') !== []);
    }

    /**
     * @param \Closure(): bool $isReached
     */
    private static function waitUntil(\Closure $isReached): void
    {
        $deadline = hrtime(true) + 5_000_000_000;
        while (!$isReached()) {
            if (hrtime(true) > $deadline) {
                self::fail('What the test waits for did not happen within five seconds.');
            }
            usleep(5_000);
        }
    }

    /**
     * @param array<string, mixed> $pageSizeConfig
     *
     * @return array<string, mixed>
     */
    private static function fullPayloadOf(array $pageSizeConfig): array
    {
        return ['kind' => 'full', 'configs' => ['page-size' => $pageSizeConfig]];
    }
}
