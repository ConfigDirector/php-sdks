<?php

declare(strict_types=1);

namespace ConfigDirector\Tests;

use ConfigDirector\Tests\Support\PhpProcess;
use ConfigDirector\Tests\Support\ScriptedSdkServer;
use ConfigDirector\Tests\Support\TemporaryFolder;
use PHPUnit\Framework\TestCase;

/**
 * @phpstan-import-type ScriptedAnswer from ScriptedSdkServer
 */
final class ClientTelemetryAcrossRequestsTest extends TestCase
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
     * @return list<array{method: string, path: string, headers: array<string, string>, body: mixed}>
     */
    private static function telemetryRequestsReceivedBy(ScriptedSdkServer $server): array
    {
        return array_values(array_filter(
            $server->requestsReceived(),
            static fn(array $request): bool => $request['path'] === '/server/telemetry/v1',
        ));
    }
}
