<?php

declare(strict_types=1);

namespace ConfigDirector\Tests;

use ConfigDirector\Tests\Support\PhpProcess;
use ConfigDirector\Tests\Support\ScriptedSdkServer;
use ConfigDirector\Tests\Support\TemporaryFolder;
use PHPUnit\Framework\Attributes\RequiresOperatingSystem;
use PHPUnit\Framework\TestCase;

/**
 * @phpstan-import-type ScriptedAnswer from ScriptedSdkServer
 * @phpstan-import-type RecordedRequest from \ConfigDirector\Tests\Support\RecordedRequests
 */
final class ClientTelemetryAcrossRequestsTest extends TestCase
{
    private const array SMALLEST_FILE_SIZE_LIMIT_OF_THE_SHELL = ['sh', '-c', 'ulimit -f 64; exec "$@"', 'sh'];

    private const int SIGNAL_OF_A_WRITE_PAST_THE_FILE_SIZE_LIMIT = 25;

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

    public function testThreeRequestsThatReadOneConfigForOneContextSendOneReportCountingThreeOnceTheFlushIntervalHasPassed(): void
    {
        $server = $this->serverAnsweringThePollThen([['status' => 200]]);
        $flushIntervalMilliseconds = 2_000;

        $firstRequestStartedAt = hrtime(true);
        $outputOfTheFirstRequest = $this->requestWithAFlushIntervalOf($flushIntervalMilliseconds)->finished()->output;
        $firstRequestEndedAt = hrtime(true);
        $outputOfTheSecondRequest = $this->requestWithAFlushIntervalOf($flushIntervalMilliseconds)->finished()->output;
        $millisecondsTheFirstTwoRequestsTook = intdiv(hrtime(true) - $firstRequestStartedAt, 1_000_000);
        $reportsSentInsideTheFlushInterval = self::telemetryRequestsReceivedBy($server);

        while (hrtime(true) - $firstRequestEndedAt < ($flushIntervalMilliseconds + 100) * 1_000_000) {
            usleep(10_000);
        }
        $outputOfTheThirdRequest = $this->requestWithAFlushIntervalOf($flushIntervalMilliseconds)->finished()->output;
        $reports = self::telemetryRequestsReceivedBy($server);

        self::assertLessThan($flushIntervalMilliseconds, $millisecondsTheFirstTwoRequestsTook);
        self::assertSame("page-size: 25\n", $outputOfTheFirstRequest);
        self::assertSame("page-size: 25\n", $outputOfTheSecondRequest);
        self::assertSame("page-size: 25\n", $outputOfTheThirdRequest);
        self::assertSame([], $reportsSentInsideTheFlushInterval);
        self::assertCount(1, $reports);
        self::assertIsArray($reports[0]['body']);
        self::assertIsArray($reports[0]['body']['aggregatedEvents']);
        self::assertIsArray($reports[0]['body']['aggregatedEvents']['evaluatedConfig']);
        self::assertSame([3], array_column($reports[0]['body']['aggregatedEvents']['evaluatedConfig'], 'count'));
        self::assertSame(
            ['page-size'],
            array_column(array_column($reports[0]['body']['aggregatedEvents']['evaluatedConfig'], 'event'), 'key'),
        );
        self::assertIsArray($reports[0]['body']['discreteEvents']);
        self::assertIsArray($reports[0]['body']['discreteEvents']['capturedContexts']);
        self::assertSame(['user-42'], array_column($reports[0]['body']['discreteEvents']['capturedContexts'], 'id'));
    }

    public function testARequestSendsTheReportOnlyAfterItsOwnOutput(): void
    {
        $server = $this->serverAnsweringThePollThen([['status' => 200]]);
        $flushIntervalMilliseconds = 1_000;
        $fileAwaitedBeforeEnding = $this->storagePath . DIRECTORY_SEPARATOR . 'end-the-request';

        $request = $this->requestWithAFlushIntervalOf($flushIntervalMilliseconds, $fileAwaitedBeforeEnding);
        $output = $request->nextLineOfOutput();
        usleep(($flushIntervalMilliseconds + 100) * 1_000);
        $reportsSentByTheTimeOfTheOutput = self::telemetryRequestsReceivedBy($server);
        touch($fileAwaitedBeforeEnding);
        $request->finished();

        self::assertSame('page-size: 25', $output);
        self::assertSame([], $reportsSentByTheTimeOfTheOutput);
        self::assertCount(1, self::telemetryRequestsReceivedBy($server));
    }

    public function testEightRequestsThatEndAtTheSameTimeAllReachTheReport(): void
    {
        $server = $this->serverAnsweringThePollThen([['status' => 200]]);
        $this->requestWithAFlushIntervalOf(60_000)->finished();
        $fileAwaitedBeforeEnding = $this->storagePath . DIRECTORY_SEPARATOR . 'end-the-requests';
        $requests = [];
        for ($request = 1; $request <= 8; $request++) {
            $requests[] = $this->requestWithAFlushIntervalOf(60_000, $fileAwaitedBeforeEnding);
        }
        foreach ($requests as $request) {
            $request->nextLineOfOutput();
        }

        touch($fileAwaitedBeforeEnding);
        foreach ($requests as $request) {
            $request->finished();
        }
        $this->outputLinesOfARequestThatEndsAfterItsFlushIntervalOfOneMillisecond();
        $reports = self::telemetryRequestsReceivedBy($server);

        self::assertCount(1, $reports);
        self::assertIsArray($reports[0]['body']);
        self::assertIsArray($reports[0]['body']['aggregatedEvents']);
        self::assertIsArray($reports[0]['body']['aggregatedEvents']['evaluatedConfig']);
        self::assertSame([10], array_column($reports[0]['body']['aggregatedEvents']['evaluatedConfig'], 'count'));
    }

    public function testTwentyRequestsThatEndWhileAnotherProcessHoldsTheTelemetryLockSharedAllReachTheReportWithoutWaitingForIt(): void
    {
        $server = $this->serverAnsweringThePollThen([['status' => 200]]);
        $this->requestWithAFlushIntervalOf(60_000)->finished();
        $fileAwaitedBeforeLettingGoOfTheLock = $this->storagePath . DIRECTORY_SEPARATOR . 'let-go-of-the-lock';
        $processHoldingTheLock = PhpProcess::running(
            __DIR__ . '/Processes/keep-a-lock-file-shared.php',
            [
                $this->localStoreFolder() . DIRECTORY_SEPARATOR . 'telemetry.lock',
                $fileAwaitedBeforeLettingGoOfTheLock,
            ],
        );
        $outputOfTheProcessHoldingTheLock = $processHoldingTheLock->nextLineOfOutput();
        $requests = [];
        for ($request = 1; $request <= 20; $request++) {
            $requests[] = $this->requestWithAFlushIntervalOf(60_000);
        }

        $outputOfEachRequest = [];
        foreach ($requests as $request) {
            $outputOfEachRequest[] = $request->finished()->output;
        }
        $theLockWasStillHeldWhenTheRequestsHadEnded = $processHoldingTheLock->isRunning();
        touch($fileAwaitedBeforeLettingGoOfTheLock);
        $processHoldingTheLock->finished();
        $this->outputLinesOfARequestThatEndsAfterItsFlushIntervalOfOneMillisecond();
        $reports = self::telemetryRequestsReceivedBy($server);

        self::assertSame('locked', $outputOfTheProcessHoldingTheLock);
        self::assertSame(array_fill(0, 20, "page-size: 25\n"), $outputOfEachRequest);
        self::assertTrue($theLockWasStillHeldWhenTheRequestsHadEnded);
        self::assertSame([22], self::evaluationCountsOfEachReport($reports));
    }

    #[RequiresOperatingSystem('Linux|Darwin')]
    public function testARequestKilledWhileItAddsItsTelemetryLosesOnlyItsOwn(): void
    {
        $server = $this->serverAnsweringThePollThen([['status' => 200]]);
        $this->requestWithAFlushIntervalOf(60_000)->finished();

        $killedRequest = PhpProcess::running(
            __DIR__ . '/Processes/serve-a-request-that-reads-a-config.php',
            [$this->storagePath, $server->url, '60000', '', '1048576'],
            commandPrefix: self::SMALLEST_FILE_SIZE_LIMIT_OF_THE_SHELL,
        )->finished();
        $this->requestWithAFlushIntervalOf(60_000)->finished();
        $this->outputLinesOfARequestThatEndsAfterItsFlushIntervalOfOneMillisecond();
        $reports = self::telemetryRequestsReceivedBy($server);

        self::assertSame(self::SIGNAL_OF_A_WRITE_PAST_THE_FILE_SIZE_LIMIT, $killedRequest->terminatingSignal);
        self::assertSame([3], self::evaluationCountsOfEachReport($reports));
    }

    public function testEightRequestsThatFindAReportDueAtTheSameTimeSendOneReportAndLeaveTheRestToTheNext(): void
    {
        $server = $this->serverAnsweringThePollThen([['status' => 200], ['status' => 200]]);
        $flushIntervalMilliseconds = 3_000;
        $this->requestWithAFlushIntervalOf($flushIntervalMilliseconds)->finished();
        usleep(($flushIntervalMilliseconds + 100) * 1_000);
        $fileAwaitedBeforeEnding = $this->storagePath . DIRECTORY_SEPARATOR . 'end-the-requests';
        $requests = [];
        for ($request = 1; $request <= 8; $request++) {
            $requests[] = $this->requestWithAFlushIntervalOf($flushIntervalMilliseconds, $fileAwaitedBeforeEnding);
        }
        foreach ($requests as $request) {
            $request->nextLineOfOutput();
        }

        touch($fileAwaitedBeforeEnding);
        foreach ($requests as $request) {
            $request->finished();
        }
        $reportsSentByTheEightRequests = count(self::telemetryRequestsReceivedBy($server));
        usleep(($flushIntervalMilliseconds + 100) * 1_000);
        $this->requestWithAFlushIntervalOf($flushIntervalMilliseconds)->finished();
        $reports = self::telemetryRequestsReceivedBy($server);

        self::assertSame(1, $reportsSentByTheEightRequests);
        self::assertCount(2, $reports);
        self::assertSame(10, array_sum(self::evaluationCountsOfEachReport($reports)));
    }

    public function testAfterARequestIsRefusedWithA401TheRequestsThatFollowSendNoReport(): void
    {
        $server = $this->serverAnsweringThePollThen([['status' => 401], ['status' => 200], ['status' => 200]]);

        $outputOfTheRefusedRequest = $this->outputLinesOfARequestThatEndsAfterItsFlushIntervalOfOneMillisecond();
        $outputOfTheSecondRequest = $this->outputLinesOfARequestThatEndsAfterItsFlushIntervalOfOneMillisecond();
        $outputOfTheThirdRequest = $this->outputLinesOfARequestThatEndsAfterItsFlushIntervalOfOneMillisecond();

        self::assertContains('page-size: 25', $outputOfTheRefusedRequest);
        self::assertContains('warning-logged 401', $outputOfTheRefusedRequest);
        self::assertSame(['page-size: 25'], $outputOfTheSecondRequest);
        self::assertSame(['page-size: 25'], $outputOfTheThirdRequest);
        self::assertCount(1, self::telemetryRequestsReceivedBy($server));
    }

    /**
     * @param list<ScriptedAnswer> $answersToReports
     */
    private function serverAnsweringThePollThen(array $answersToReports): ScriptedSdkServer
    {
        return $this->server = ScriptedSdkServer::answering([
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
            ...$answersToReports,
        ]);
    }

    private function requestWithAFlushIntervalOf(int $milliseconds, string $fileAwaitedBeforeEnding = ''): PhpProcess
    {
        return PhpProcess::running(
            __DIR__ . '/Processes/serve-a-request-that-reads-a-config.php',
            [$this->storagePath, $this->server->url ?? '', (string) $milliseconds, $fileAwaitedBeforeEnding],
        );
    }

    private function localStoreFolder(): string
    {
        return $this->storagePath . DIRECTORY_SEPARATOR . hash('sha256', 'server-sdk-key');
    }

    /**
     * @return list<string>
     */
    private function outputLinesOfARequestThatEndsAfterItsFlushIntervalOfOneMillisecond(): array
    {
        $fileAwaitedBeforeEnding = $this->storagePath . DIRECTORY_SEPARATOR . 'end-' . bin2hex(random_bytes(4));
        $request = $this->requestWithAFlushIntervalOf(1, $fileAwaitedBeforeEnding);
        $firstLineOfOutput = $request->nextLineOfOutput();
        usleep(5_000);
        touch($fileAwaitedBeforeEnding);

        return [$firstLineOfOutput, ...array_filter(explode("\n", $request->finished()->output))];
    }

    /**
     * @param list<RecordedRequest> $reports
     *
     * @return list<mixed>
     */
    private static function evaluationCountsOfEachReport(array $reports): array
    {
        $evaluationCountsOfEachReport = [];
        foreach ($reports as $report) {
            self::assertIsArray($report['body']);
            self::assertIsArray($report['body']['aggregatedEvents']);
            self::assertIsArray($report['body']['aggregatedEvents']['evaluatedConfig']);
            $evaluationCountsOfEachReport[] = array_sum(
                array_column($report['body']['aggregatedEvents']['evaluatedConfig'], 'count'),
            );
        }

        return $evaluationCountsOfEachReport;
    }

    /**
     * @return list<RecordedRequest>
     */
    private static function telemetryRequestsReceivedBy(ScriptedSdkServer $server): array
    {
        return array_values(array_filter(
            $server->requestsReceived(),
            static fn(array $request): bool => $request['path'] === '/server/telemetry/v1',
        ));
    }
}
