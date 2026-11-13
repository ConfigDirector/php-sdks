<?php

declare(strict_types=1);

namespace ConfigDirector\Tests\Internal\Client;

use ConfigDirector\ConfigDirectorClient;
use ConfigDirector\Context;
use ConfigDirector\Internal\Client\DefaultConfigDirectorClient;
use ConfigDirector\Internal\Client\EventHandlers;
use ConfigDirector\Internal\Connection\ServerSdkPayload;
use ConfigDirector\Internal\Identity\SdkIdentity;
use ConfigDirector\Internal\LocalStore\ExclusiveFileLock;
use ConfigDirector\Internal\LocalStore\LocalStoreOpenedOnFirstUse;
use ConfigDirector\Internal\Telemetry\LocalStoreTelemetry;
use ConfigDirector\Internal\Telemetry\TelemetryEndpoint;
use ConfigDirector\Internal\Time\ClientLifetime;
use ConfigDirector\Metadata;
use ConfigDirector\Tests\Support\CloseCountingConnection;
use ConfigDirector\Tests\Support\RecordingLogger;
use ConfigDirector\Tests\Support\ScriptedSdkServer;
use ConfigDirector\Tests\Support\SettableClock;
use ConfigDirector\Tests\Support\TemporaryFolder;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

/**
 * @phpstan-import-type ScriptedAnswer from ScriptedSdkServer
 */
final class ClientTelemetryOverTimeTest extends TestCase
{
    private const int NOVEMBER_12_2026_AT_NOON = 1_794_484_800_000;

    private const int FLUSH_INTERVAL_MILLISECONDS = 30_000;

    private const int ONE_HOUR_MILLISECONDS = 3_600_000;

    private string $storagePath;

    private SettableClock $clock;

    private RecordingLogger $logger;

    /** @var list<ScriptedSdkServer> */
    private array $servers = [];

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
        foreach ($this->servers as $server) {
            $server->stop();
        }
        TemporaryFolder::remove($this->storagePath);
    }

    public function testARequestThatEndsOneMillisecondBeforeTheFlushIntervalHasPassedSendsNothing(): void
    {
        $server = $this->serverAnswering([['status' => 200]]);
        $this->aRequestReads('page-size', $server);
        $this->clock->epochMilliseconds += self::FLUSH_INTERVAL_MILLISECONDS - 1;

        $this->aRequestEnds($server);

        self::assertSame([], $server->requestsReceived());
    }

    public function testTheFirstRequestToEndAFlushIntervalAfterTheFirstClientWasCreatedSendsTheReport(): void
    {
        $server = $this->serverAnswering([['status' => 200]]);
        $this->aRequestReads('page-size', $server);
        $this->clock->epochMilliseconds += self::FLUSH_INTERVAL_MILLISECONDS;

        $this->aRequestEnds($server);

        self::assertSame([['page-size']], self::keysReportedTo($server));
    }

    public function testEveryEntryOfAReportStartsWhenItsClientWasCreatedAndEndsWhenTheReportIsSent(): void
    {
        $server = $this->serverAnswering([['status' => 200]]);
        $this->aRequestReads('page-size', $server);
        $this->clock->epochMilliseconds += self::FLUSH_INTERVAL_MILLISECONDS;

        $this->aRequestEnds($server);

        [$report] = $server->requestsReceived();
        self::assertIsArray($report['body']);
        self::assertIsArray($report['body']['aggregatedEvents']);
        self::assertIsArray($report['body']['aggregatedEvents']['evaluatedConfig']);
        self::assertIsArray($report['body']['aggregatedEvents']['evaluatedConfig'][0]);
        self::assertSame(
            '2026-11-12T12:00:00.000Z',
            $report['body']['aggregatedEvents']['evaluatedConfig'][0]['startTime'],
        );
        self::assertSame(
            '2026-11-12T12:00:30.000Z',
            $report['body']['aggregatedEvents']['evaluatedConfig'][0]['endTime'],
        );
    }

    public function testAfterAReportTheNextOneIsSentAFlushIntervalLater(): void
    {
        $server = $this->serverAnswering([['status' => 200], ['status' => 200]]);
        $this->aRequestReads('page-size', $server);
        $this->clock->epochMilliseconds += self::FLUSH_INTERVAL_MILLISECONDS;
        $this->aRequestEnds($server);
        $this->clock->epochMilliseconds += 1;
        $this->aRequestReads('banner', $server);
        $this->clock->epochMilliseconds += self::FLUSH_INTERVAL_MILLISECONDS - 2;
        $this->aRequestEnds($server);
        $reportsSentBeforeTheSecondFlushIntervalPassed = count($server->requestsReceived());
        $this->clock->epochMilliseconds += 1;

        $this->aRequestEnds($server);

        self::assertSame(1, $reportsSentBeforeTheSecondFlushIntervalPassed);
        self::assertSame([['page-size'], ['banner']], self::keysReportedTo($server));
        self::assertSame(
            ['2026-11-12T12:00:00.000Z', '2026-11-12T12:00:30.001Z'],
            self::startTimesReportedTo($server),
        );
    }

    public function testARequestThatEndsWhenNothingWasCollectedSinceTheLastReportSendsNothing(): void
    {
        $server = $this->serverAnswering([['status' => 200], ['status' => 200]]);
        $this->aRequestReads('page-size', $server);
        $this->clock->epochMilliseconds += self::FLUSH_INTERVAL_MILLISECONDS;
        $this->aRequestEnds($server);
        $this->clock->epochMilliseconds += self::FLUSH_INTERVAL_MILLISECONDS;

        $this->aRequestEnds($server);

        self::assertCount(1, $server->requestsReceived());
    }

    public function testAReadOfADisposedClientSendsNothing(): void
    {
        $server = $this->serverAnswering([['status' => 200]]);
        $client = $this->clientThatOpenedTheLocalStore($server);
        $client->dispose();
        $this->aRequestReads('page-size', $server);
        $this->clock->epochMilliseconds += self::FLUSH_INTERVAL_MILLISECONDS + 1;

        $client->getValue('banner', 'Welcome');

        self::assertSame([], $server->requestsReceived());
    }

    public function testARequestThatEndsLongAfterTheLastReportSendsItsOwnTelemetryAtOnce(): void
    {
        $server = $this->serverAnswering([['status' => 200], ['status' => 200]]);
        $this->aRequestReads('page-size', $server);
        $this->clock->epochMilliseconds += self::FLUSH_INTERVAL_MILLISECONDS;
        $this->aRequestEnds($server);
        $this->clock->epochMilliseconds += 600_000;

        $this->aRequestReads('banner', $server);

        self::assertSame([['page-size'], ['banner']], self::keysReportedTo($server));
    }

    public function testAClientOlderThanTheFlushIntervalSendsItsEvaluationsInTheReadThatFindsThemDue(): void
    {
        $server = $this->serverAnswering([['status' => 200]]);
        $client = $this->clientThatOpenedTheLocalStore($server);
        $this->clock->epochMilliseconds += 1_000;
        $client->getValue('page-size', 10);
        $this->clock->epochMilliseconds += self::FLUSH_INTERVAL_MILLISECONDS - 999;

        $client->getValue('page-size', 10);

        [$report] = $server->requestsReceived();
        self::assertIsArray($report['body']);
        self::assertIsArray($report['body']['aggregatedEvents']);
        self::assertIsArray($report['body']['aggregatedEvents']['evaluatedConfig']);
        self::assertSame([2], array_column($report['body']['aggregatedEvents']['evaluatedConfig'], 'count'));
    }

    public function testAClientExactlyAsOldAsTheFlushIntervalSendsNothingInARead(): void
    {
        $server = $this->serverAnswering([['status' => 200]]);
        $client = $this->clientThatOpenedTheLocalStore($server);
        $this->clock->epochMilliseconds += self::FLUSH_INTERVAL_MILLISECONDS;

        $client->getValue('page-size', 10);

        self::assertSame([], $server->requestsReceived());
    }

    public function testAClientOfALongRunningProcessSendsOneReportForEachFlushInterval(): void
    {
        $server = $this->serverAnswering([['status' => 200], ['status' => 200]]);
        $client = $this->clientThatOpenedTheLocalStore($server);
        $this->clock->epochMilliseconds += self::FLUSH_INTERVAL_MILLISECONDS + 1;
        $client->getValue('page-size', 10);
        $this->clock->epochMilliseconds += 15_000;
        $client->getValue('banner', 'Welcome');
        $this->clock->epochMilliseconds += 15_000;
        $client->getValue('banner', 'Welcome');
        $reportsSentBeforeTheSecondFlushIntervalPassed = count($server->requestsReceived());
        $this->clock->epochMilliseconds += 1;

        $client->getValue('theme', 'light');

        self::assertSame(1, $reportsSentBeforeTheSecondFlushIntervalPassed);
        self::assertSame([['page-size'], ['banner', 'theme']], self::keysReportedTo($server));
    }

    public function testTwoLongRunningProcessesOfOneHostSendOneReportForEachFlushIntervalBetweenThem(): void
    {
        $server = $this->serverAnswering([['status' => 200], ['status' => 200]]);
        $clientOfTheFirstProcess = $this->clientThatOpenedTheLocalStore($server);
        $this->clock->epochMilliseconds += 5_000;
        $clientOfTheSecondProcess = $this->clientThatOpenedTheLocalStore($server);
        $this->clock->epochMilliseconds += 25_001;
        $clientOfTheFirstProcess->getValue('page-size', 10);
        $this->clock->epochMilliseconds += 5_000;
        $clientOfTheSecondProcess->getValue('banner', 'Welcome');
        $reportsSentWhenTheSecondProcessFoundItsEvaluationsDue = count($server->requestsReceived());
        $this->clock->epochMilliseconds += 25_001;

        $clientOfTheFirstProcess->getValue('theme', 'light');

        self::assertSame(1, $reportsSentWhenTheSecondProcessFoundItsEvaluationsDue);
        self::assertSame([['page-size'], ['banner', 'theme']], self::keysReportedTo($server));
    }

    public function testDisposingAClientOlderThanTheFlushIntervalSendsWhatTheLocalStoreHoldsAtOnce(): void
    {
        $server = $this->serverAnswering([['status' => 200]]);
        $clientOfALongRunningProcess = $this->clientThatOpenedTheLocalStore($server);
        $this->clock->epochMilliseconds += self::FLUSH_INTERVAL_MILLISECONDS + 1;
        $this->aRequestReads('page-size', $server);

        $clientOfALongRunningProcess->dispose();

        self::assertSame([['page-size']], self::keysReportedTo($server));
    }

    public function testDisposingAClientExactlyAsOldAsTheFlushIntervalSendsNothingBeforeTheReportIsDue(): void
    {
        $server = $this->serverAnswering([['status' => 200]]);
        $client = $this->clientThatOpenedTheLocalStore($server);
        $this->clock->epochMilliseconds += self::FLUSH_INTERVAL_MILLISECONDS;
        $this->aRequestReads('page-size', $server);

        $client->dispose();

        self::assertSame([], $server->requestsReceived());
    }

    public function testDisposingSendsTheReportOnlyAfterTheConnectionWasClosed(): void
    {
        $server = $this->serverAnswering([['status' => 200]]);
        $this->aRequestReads('page-size', $server);
        $this->clock->epochMilliseconds += self::FLUSH_INTERVAL_MILLISECONDS;
        $reportsSentWhenTheConnectionWasClosed = null;
        $client = $this->clientThatOpenedTheLocalStore(
            $server,
            static function () use ($server, &$reportsSentWhenTheConnectionWasClosed): void {
                $reportsSentWhenTheConnectionWasClosed = count($server->requestsReceived());
            },
        );

        $client->dispose();

        self::assertSame(0, $reportsSentWhenTheConnectionWasClosed);
        self::assertCount(1, $server->requestsReceived());
    }

    public function testNothingIsSentUntilOneHourAfterARefusal(): void
    {
        $server = $this->serverAnswering([['status' => 401], ['status' => 200]]);
        $this->aRequestReads('page-size', $server);
        $this->clock->epochMilliseconds += self::FLUSH_INTERVAL_MILLISECONDS;
        $this->aRequestEnds($server);
        $this->clock->epochMilliseconds += 1_000;
        $this->aRequestReads('banner', $server);
        $this->clock->epochMilliseconds += self::ONE_HOUR_MILLISECONDS - 1_001;

        $this->aRequestEnds($server);

        self::assertCount(1, $server->requestsReceived());
    }

    public function testOneHourAfterARefusalTelemetryIsCollectedAgainWithoutWhatWasReadMeanwhile(): void
    {
        $server = $this->serverAnswering([['status' => 401], ['status' => 200]]);
        $this->aRequestReads('page-size', $server);
        $this->clock->epochMilliseconds += self::FLUSH_INTERVAL_MILLISECONDS;
        $this->aRequestEnds($server);
        $this->clock->epochMilliseconds += self::ONE_HOUR_MILLISECONDS - 1;
        $this->aRequestReads('banner', $server);
        $this->clock->epochMilliseconds += 1;
        $this->aRequestReads('theme', $server);
        $reportsSentWhenTheHourHadJustPassed = count($server->requestsReceived());
        $this->clock->epochMilliseconds += self::FLUSH_INTERVAL_MILLISECONDS;

        $this->aRequestEnds($server);

        [, $reportAfterTheHour] = $server->requestsReceived();
        self::assertIsArray($reportAfterTheHour['body']);
        self::assertSame(1, $reportsSentWhenTheHourHadJustPassed);
        self::assertSame([['page-size'], ['theme']], self::keysReportedTo($server));
        self::assertSame(
            ['evaluatedConfig' => 0, 'capturedContexts' => 0],
            $reportAfterTheHour['body']['droppedEvents'],
        );
    }

    public function testARefusalByOneUrlDoesNotStopTheTelemetrySentToAnotherUrl(): void
    {
        $serverThatRefuses = $this->serverAnswering([['status' => 401]]);
        $anotherServer = $this->serverAnswering([['status' => 200]]);
        $this->aRequestReads('page-size', $serverThatRefuses);
        $this->clock->epochMilliseconds += self::FLUSH_INTERVAL_MILLISECONDS;
        $this->aRequestEnds($serverThatRefuses);
        $this->clock->epochMilliseconds += 1_000;
        $this->aRequestReads('banner', $anotherServer);
        $this->clock->epochMilliseconds += self::FLUSH_INTERVAL_MILLISECONDS;

        $this->aRequestEnds($anotherServer);

        self::assertSame([['banner']], self::keysReportedTo($anotherServer));
    }

    public function testARefusalRecordedByAClockThatWasSetBackSinceDoesNotStopTelemetry(): void
    {
        $server = $this->serverAnswering([['status' => 401], ['status' => 200]]);
        $this->aRequestReads('page-size', $server);
        $this->clock->epochMilliseconds += self::FLUSH_INTERVAL_MILLISECONDS;
        $this->aRequestEnds($server);
        $this->clock->setTheWallClockBackBy(120_000);
        $this->aRequestReads('banner', $server);
        $this->clock->epochMilliseconds += self::FLUSH_INTERVAL_MILLISECONDS;

        $this->aRequestEnds($server);

        self::assertSame([['page-size'], ['banner']], self::keysReportedTo($server));
    }

    public function testTelemetryStoredByAClockThatWasSetBackSinceIsSentAtOnce(): void
    {
        $server = $this->serverAnswering([['status' => 200]]);
        $this->aRequestReads('page-size', $server);
        $this->clock->setTheWallClockBackBy(60_000);

        $this->aRequestEnds($server);

        self::assertSame([['page-size']], self::keysReportedTo($server));
        self::assertSame(['2026-11-12T11:59:00.000Z'], self::startTimesReportedTo($server));
    }

    public function testAClientOfALongRunningProcessLogsAnInvalidKindAgainOnceItMovedItsTelemetryToTheLocalStore(): void
    {
        $server = $this->serverAnswering([['status' => 200]]);
        $client = $this->clientThatOpenedTheLocalStore($server);
        $client->getValue('page-size', 10, new Context(kind: 'Device'));
        $client->getValue('page-size', 10, new Context(kind: 'Device'));
        $this->clock->epochMilliseconds += self::FLUSH_INTERVAL_MILLISECONDS + 1;
        $client->getValue('page-size', 10, new Context(kind: 'Device'));
        $errorsLoggedBeforeTheNextRead = $this->logger->contextsLoggedAt(LogLevel::ERROR);

        $client->getValue('page-size', 10, new Context(kind: 'Device'));
        $client->getValue('page-size', 10, new Context(kind: 'Device'));

        self::assertSame([['kindSample' => 'Device']], $errorsLoggedBeforeTheNextRead);
        self::assertSame(
            [['kindSample' => 'Device'], ['kindSample' => 'Device']],
            $this->logger->contextsLoggedAt(LogLevel::ERROR),
        );
    }

    public function testAClientWhoseTelemetryCannotBeWrittenLogsOneWarningHoweverOftenItTries(): void
    {
        $server = $this->serverAnswering([['status' => 200], ['status' => 200]]);
        $client = $this->clientThatOpenedTheLocalStore($server);
        $telemetryFile = $this->localStoreFolder() . DIRECTORY_SEPARATOR . 'telemetry.jsonl';
        mkdir($telemetryFile);
        $this->clock->epochMilliseconds += self::FLUSH_INTERVAL_MILLISECONDS + 1;
        $client->getValue('page-size', 10);
        $this->clock->epochMilliseconds += self::FLUSH_INTERVAL_MILLISECONDS + 1;

        $client->getValue('page-size', 10);

        self::assertSame([['file' => $telemetryFile]], $this->logger->contextsLoggedAt(LogLevel::WARNING));
    }

    public function testARequestThatFindsTheTelemetryLockedByAnotherProcessEndsWithoutItsTelemetryAndLogsOneWarning(): void
    {
        $server = $this->serverAnswering([['status' => 200]]);
        $client = $this->clientThatOpenedTheLocalStore($server);
        $client->getValue('page-size', 10);
        $lockOfAnotherProcess = ExclusiveFileLock::acquiredWithin(
            0,
            $this->localStoreFolder() . DIRECTORY_SEPARATOR . 'telemetry.lock',
        );
        self::assertInstanceOf(ExclusiveFileLock::class, $lockOfAnotherProcess);

        $disposalStartedAt = hrtime(true);
        $client->dispose();
        $millisecondsTheDisposalTook = intdiv(hrtime(true) - $disposalStartedAt, 1_000_000);
        $lockOfAnotherProcess->release();
        $this->aRequestReads('banner', $server);
        $this->clock->epochMilliseconds += self::FLUSH_INTERVAL_MILLISECONDS;
        $this->aRequestEnds($server);

        self::assertSame([['banner']], self::keysReportedTo($server));
        self::assertSame(
            [['file' => $this->localStoreFolder() . DIRECTORY_SEPARATOR . 'telemetry.lock']],
            $this->logger->contextsLoggedAt(LogLevel::WARNING),
        );
        self::assertLessThan(1_000, $millisecondsTheDisposalTook);
    }

    /**
     * @param list<ScriptedAnswer> $answersToReports
     */
    private function serverAnswering(array $answersToReports): ScriptedSdkServer
    {
        return $this->servers[] = ScriptedSdkServer::answering($answersToReports);
    }

    private function aRequestReads(string $configKey, ScriptedSdkServer $server): void
    {
        $client = $this->clientThatOpenedTheLocalStore($server);
        $client->getValue($configKey, 'in-code default value');
        $client->dispose();
    }

    private function aRequestEnds(ScriptedSdkServer $server): void
    {
        $this->clientThatOpenedTheLocalStore($server)->dispose();
    }

    /**
     * @param (\Closure(): void)|null $whileTheConnectionCloses
     */
    private function clientThatOpenedTheLocalStore(
        ScriptedSdkServer $server,
        ?\Closure $whileTheConnectionCloses = null,
    ): ConfigDirectorClient {
        $localStore = new LocalStoreOpenedOnFirstUse('server-sdk-key', $this->storagePath, $this->logger);
        $localStore->store();

        return new DefaultConfigDirectorClient(
            new CloseCountingConnection(new ServerSdkPayload([], []), $whileTheConnectionCloses),
            new Metadata(),
            3_000,
            $this->logger,
            new EventHandlers($this->logger),
            new LocalStoreTelemetry(
                $localStore,
                new TelemetryEndpoint($server->url, 'server-sdk-key', new Metadata(), SdkIdentity::ofTheServerSdk()),
                5_000,
                self::FLUSH_INTERVAL_MILLISECONDS,
                new ClientLifetime($this->clock, self::FLUSH_INTERVAL_MILLISECONDS),
                $this->clock,
                $this->logger,
            ),
        );
    }

    private function localStoreFolder(): string
    {
        return $this->storagePath . DIRECTORY_SEPARATOR . hash('sha256', 'server-sdk-key');
    }

    /**
     * @return list<mixed>
     */
    private static function startTimesReportedTo(ScriptedSdkServer $server): array
    {
        $startTimeOfEachReport = [];
        foreach ($server->requestsReceived() as $report) {
            self::assertIsArray($report['body']);
            self::assertIsArray($report['body']['aggregatedEvents']);
            self::assertIsArray($report['body']['aggregatedEvents']['evaluatedConfig']);
            self::assertIsArray($report['body']['aggregatedEvents']['evaluatedConfig'][0]);
            $startTimeOfEachReport[] = $report['body']['aggregatedEvents']['evaluatedConfig'][0]['startTime'];
        }

        return $startTimeOfEachReport;
    }

    /**
     * @return list<list<mixed>>
     */
    private static function keysReportedTo(ScriptedSdkServer $server): array
    {
        $keysOfEachReport = [];
        foreach ($server->requestsReceived() as $report) {
            self::assertIsArray($report['body']);
            self::assertIsArray($report['body']['aggregatedEvents']);
            self::assertIsArray($report['body']['aggregatedEvents']['evaluatedConfig']);
            $keysOfEachReport[] = array_column(
                array_column($report['body']['aggregatedEvents']['evaluatedConfig'], 'event'),
                'key',
            );
        }

        return $keysOfEachReport;
    }
}
