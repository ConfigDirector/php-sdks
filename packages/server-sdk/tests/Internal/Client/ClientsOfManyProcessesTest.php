<?php

declare(strict_types=1);

namespace ConfigDirector\Tests\Internal\Client;

use ConfigDirector\Tests\Support\PhpProcess;
use ConfigDirector\Tests\Support\ScriptedSdkServer;
use ConfigDirector\Tests\Support\TemporaryFolder;
use PHPUnit\Framework\TestCase;

/**
 * @phpstan-import-type RecordedRequest from \ConfigDirector\Tests\Support\RecordedRequests
 */
final class ClientsOfManyProcessesTest extends TestCase
{
    private const int PROCESSES = 50;

    private const int TIMES_FASTER_THAN_REAL_TIME = 60;

    private const int POLLING_INTERVAL_MILLISECONDS = 60_000;

    private const int FLUSH_INTERVAL_MILLISECONDS = 120_000;

    private const int REQUESTS_UNTIL_THE_FILE_THAT_ENDS_THEM_APPEARS = 1_000_000;

    private string $storagePath;

    private string $folderOfTheRecords;

    private int $clockStartedAtEpochMilliseconds;

    private ?ScriptedSdkServer $server = null;

    /** @var array<string, PhpProcess> */
    private array $processesStillRunningByContextId = [];

    #[\Override]
    protected function setUp(): void
    {
        $this->storagePath = TemporaryFolder::create();
        $this->folderOfTheRecords = TemporaryFolder::create();
        $this->clockStartedAtEpochMilliseconds = (int) floor(microtime(true) * 1_000);
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->processesStillRunningByContextId as $process) {
            $process->stop();
        }
        $this->server?->stop();
        TemporaryFolder::remove($this->storagePath);
        TemporaryFolder::remove($this->folderOfTheRecords);
    }

    public function testFiftyProcessesServingRequestsForFivePollingIntervalsPollOncePerIntervalAndReportEveryReadOnce(): void
    {
        $server = $this->serverServingAPageSizeOf25();
        $acceleratedMillisecondsBeforeTheFirstRequest = $this->startFiftyProcessesThatServeRequestsOnceAllHaveStarted();

        self::waitForAcceleratedMilliseconds(
            5 * self::POLLING_INTERVAL_MILLISECONDS + self::FLUSH_INTERVAL_MILLISECONDS,
        );
        $this->endTheRequestsOfEveryProcess();
        $acceleratedMillisecondsTheProcessesRan = $this->acceleratedMillisecondsSinceTheClockStarted()
            - $acceleratedMillisecondsBeforeTheFirstRequest;
        self::waitForAcceleratedMilliseconds(self::FLUSH_INTERVAL_MILLISECONDS);
        $this->serveOneRequestFor('user-of-the-last-request');
        $acceleratedMillisecondsUntilTheLastRequestEnded = $this->acceleratedMillisecondsSinceTheClockStarted();

        $polls = self::requestsTo('/server/polling/v1', $server);
        $reports = self::requestsTo('/server/telemetry/v1', $server);
        $readsRecordedByContextId = $this->readsRecordedByContextId();
        self::assertSame('', $this->standardErrorOfEveryProcess());
        self::assertSame([], $this->recordedLinesOtherThanAReadOf25());
        self::assertCount(self::PROCESSES + 1, $readsRecordedByContextId);
        self::assertSame(1, $readsRecordedByContextId['user-of-the-last-request']);
        self::assertLessThanOrEqual(
            intdiv($acceleratedMillisecondsUntilTheLastRequestEnded, self::POLLING_INTERVAL_MILLISECONDS) + 1,
            count($polls),
        );
        self::assertGreaterThanOrEqual(
            intdiv($acceleratedMillisecondsTheProcessesRan, self::POLLING_INTERVAL_MILLISECONDS) - 1,
            count($polls),
        );
        self::assertCount(1, array_unique(array_map(self::sessionIdOf(...), $polls)));
        self::assertLessThanOrEqual(
            intdiv($acceleratedMillisecondsUntilTheLastRequestEnded, self::FLUSH_INTERVAL_MILLISECONDS) + 1,
            count($reports),
        );
        self::assertGreaterThanOrEqual(
            intdiv($acceleratedMillisecondsTheProcessesRan, self::FLUSH_INTERVAL_MILLISECONDS) - 2,
            count($reports),
        );
        self::assertSame($readsRecordedByContextId, self::evaluationsReportedByContextId($reports));
        self::assertSame(array_fill(0, count($reports), 0), self::evaluationsReportedAsDroppedIn($reports));
    }

    public function testProcessesKilledWhileFiftyServeRequestsLeaveTheLocalStoreUsableAndItsLocksFree(): void
    {
        $server = $this->serverServingAPageSizeOf25();
        $this->startFiftyProcessesThatServeRequestsOnceAllHaveStarted();
        $this->waitUntilEveryProcessHasServedARequest();

        $killingEndsAt = hrtime(true) + 3_000_000_000;
        for ($kill = 0; hrtime(true) < $killingEndsAt; $kill++) {
            $contextId = 'user-' . ($kill % self::PROCESSES + 1);
            $this->processesStillRunningByContextId[$contextId]->killAtOnce();
            $this->startAProcessServingRequestsFor($contextId);
            usleep(150_000);
        }
        $this->endTheRequestsOfEveryProcess();
        self::waitForAcceleratedMilliseconds(self::POLLING_INTERVAL_MILLISECONDS + self::FLUSH_INTERVAL_MILLISECONDS);
        $pollsBeforeTheLastRequest = count(self::requestsTo('/server/polling/v1', $server));
        $this->serveOneRequestFor('user-of-the-last-request');
        $acceleratedMillisecondsUntilTheLastRequestEnded = $this->acceleratedMillisecondsSinceTheClockStarted();

        $polls = self::requestsTo('/server/polling/v1', $server);
        $readsRecordedByContextId = $this->readsRecordedByContextId();
        $evaluationsReportedByContextId = self::evaluationsReportedByContextId(
            self::requestsTo('/server/telemetry/v1', $server),
        );
        self::assertSame('', $this->standardErrorOfEveryProcess());
        self::assertSame([], $this->recordedLinesOtherThanAReadOf25());
        self::assertSame(1, $readsRecordedByContextId['user-of-the-last-request']);
        self::assertCount($pollsBeforeTheLastRequest + 1, $polls);
        self::assertLessThanOrEqual(
            intdiv($acceleratedMillisecondsUntilTheLastRequestEnded, self::POLLING_INTERVAL_MILLISECONDS) + 1,
            count($polls),
        );
        self::assertSame(1, $evaluationsReportedByContextId['user-of-the-last-request'] ?? 0);
        self::assertSame(
            [],
            array_filter(
                $evaluationsReportedByContextId,
                static fn(int $evaluationsReported, string $contextId): bool
                    => $evaluationsReported > ($readsRecordedByContextId[$contextId] ?? 0),
                ARRAY_FILTER_USE_BOTH,
            ),
        );
    }

    private function serverServingAPageSizeOf25(): ScriptedSdkServer
    {
        return $this->server = ScriptedSdkServer::answeringEveryRequestWith([
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
        ]);
    }

    private function startFiftyProcessesThatServeRequestsOnceAllHaveStarted(): int
    {
        $fileAwaitedBeforeTheFirstRequest = $this->folderOfTheRecords . DIRECTORY_SEPARATOR . 'serve-the-requests';
        for ($process = 1; $process <= self::PROCESSES; $process++) {
            $this->processesStillRunningByContextId["user-{$process}"] = $this->processServingRequestsFor(
                "user-{$process}",
                self::REQUESTS_UNTIL_THE_FILE_THAT_ENDS_THEM_APPEARS,
                $fileAwaitedBeforeTheFirstRequest,
            );
        }
        foreach ($this->processesStillRunningByContextId as $process) {
            self::assertSame('started', $process->nextLineOfOutput());
        }

        $acceleratedMillisecondsBeforeTheFirstRequest = $this->acceleratedMillisecondsSinceTheClockStarted();
        touch($fileAwaitedBeforeTheFirstRequest);

        return $acceleratedMillisecondsBeforeTheFirstRequest;
    }

    private function startAProcessServingRequestsFor(string $contextId): void
    {
        $this->processesStillRunningByContextId[$contextId] = $this->processServingRequestsFor(
            $contextId,
            self::REQUESTS_UNTIL_THE_FILE_THAT_ENDS_THEM_APPEARS,
            fileAwaitedBeforeTheFirstRequest: '',
        );
    }

    private function serveOneRequestFor(string $contextId): void
    {
        $this->processServingRequestsFor($contextId, requestsToServe: 1, fileAwaitedBeforeTheFirstRequest: '')
            ->finished();
    }

    private function processServingRequestsFor(
        string $contextId,
        int $requestsToServe,
        string $fileAwaitedBeforeTheFirstRequest,
    ): PhpProcess {
        return PhpProcess::running(
            __DIR__ . '/Processes/serve-requests-on-an-accelerated-clock.php',
            [
                $this->storagePath,
                $this->server->url ?? '',
                (string) $this->clockStartedAtEpochMilliseconds,
                (string) self::TIMES_FASTER_THAN_REAL_TIME,
                (string) self::FLUSH_INTERVAL_MILLISECONDS,
                $contextId,
                $this->folderOfTheRecords . DIRECTORY_SEPARATOR . "{$contextId}.record",
                (string) $requestsToServe,
                $fileAwaitedBeforeTheFirstRequest,
                $this->fileThatEndsTheRequests(),
            ],
            fileForStandardError: $this->fileOfStandardError(),
        );
    }

    private function endTheRequestsOfEveryProcess(): void
    {
        touch($this->fileThatEndsTheRequests());
        foreach ($this->processesStillRunningByContextId as $process) {
            $process->finished();
        }
        $this->processesStillRunningByContextId = [];
        unlink($this->fileThatEndsTheRequests());
    }

    private function waitUntilEveryProcessHasServedARequest(): void
    {
        $waitEndsAt = hrtime(true) + 30_000_000_000;
        while (count(array_filter($this->readsRecordedByContextId())) < self::PROCESSES) {
            if (hrtime(true) >= $waitEndsAt) {
                self::fail('Not every process served a request within 30 seconds.');
            }
            usleep(10_000);
        }
    }

    private function fileThatEndsTheRequests(): string
    {
        return $this->folderOfTheRecords . DIRECTORY_SEPARATOR . 'end-the-requests';
    }

    private function fileOfStandardError(): string
    {
        return $this->folderOfTheRecords . DIRECTORY_SEPARATOR . 'standard-error';
    }

    private function standardErrorOfEveryProcess(): string
    {
        return is_file($this->fileOfStandardError()) ? (string) file_get_contents($this->fileOfStandardError()) : '';
    }

    private function acceleratedMillisecondsSinceTheClockStarted(): int
    {
        $realMilliseconds = (int) ceil(microtime(true) * 1_000) - $this->clockStartedAtEpochMilliseconds;

        return $realMilliseconds * self::TIMES_FASTER_THAN_REAL_TIME;
    }

    private static function waitForAcceleratedMilliseconds(int $acceleratedMilliseconds): void
    {
        $waitEndsAt = hrtime(true) + intdiv($acceleratedMilliseconds * 1_000_000, self::TIMES_FASTER_THAN_REAL_TIME);
        while (hrtime(true) < $waitEndsAt) {
            usleep(5_000);
        }
    }

    /**
     * @return array<string, int>
     */
    private function readsRecordedByContextId(): array
    {
        $readsRecordedByContextId = [];
        foreach ($this->finishedLinesRecordedByContextId() as $contextId => $lines) {
            $readsRecordedByContextId[$contextId] = count(array_keys($lines, 'read 25', true));
        }
        ksort($readsRecordedByContextId);

        return $readsRecordedByContextId;
    }

    /**
     * @return list<string>
     */
    private function recordedLinesOtherThanAReadOf25(): array
    {
        return array_values(array_unique(array_filter(
            array_merge(...array_values($this->finishedLinesRecordedByContextId())),
            static fn(string $line): bool => $line !== 'read 25',
        )));
    }

    /**
     * @return array<string, list<string>>
     */
    private function finishedLinesRecordedByContextId(): array
    {
        $linesByContextId = [];
        foreach (TemporaryFolder::entriesOf($this->folderOfTheRecords) as $entry) {
            if (!str_ends_with($entry, '.record')) {
                continue;
            }

            $recordedText = (string) file_get_contents($this->folderOfTheRecords . DIRECTORY_SEPARATOR . $entry);
            $endOfTheLastFinishedLine = strrpos($recordedText, "\n");
            $linesByContextId[basename($entry, '.record')] = $endOfTheLastFinishedLine === false
                ? []
                : explode("\n", substr($recordedText, 0, $endOfTheLastFinishedLine));
        }

        return $linesByContextId;
    }

    /**
     * @return list<RecordedRequest>
     */
    private static function requestsTo(string $path, ScriptedSdkServer $server): array
    {
        return array_values(array_filter(
            $server->requestsReceived(),
            static fn(array $request): bool => $request['path'] === $path,
        ));
    }

    /**
     * @param RecordedRequest $poll
     */
    private static function sessionIdOf(array $poll): string
    {
        $sessionId = is_array($poll['body']) ? $poll['body']['sessionId'] ?? null : null;

        return is_string($sessionId) ? $sessionId : '';
    }

    /**
     * @param list<RecordedRequest> $reports
     *
     * @return array<string, int>
     */
    private static function evaluationsReportedByContextId(array $reports): array
    {
        $evaluationsReportedByContextId = [];
        foreach ($reports as $report) {
            $aggregatedEvents = is_array($report['body']) ? $report['body']['aggregatedEvents'] ?? null : null;
            $evaluations = is_array($aggregatedEvents) ? $aggregatedEvents['evaluatedConfig'] ?? null : null;
            foreach (is_array($evaluations) ? $evaluations : [] as $evaluation) {
                $event = is_array($evaluation) ? $evaluation['event'] ?? null : null;
                $contextId = is_array($event) ? $event['contextId'] ?? null : null;
                $count = is_array($evaluation) ? $evaluation['count'] ?? null : null;
                if (is_string($contextId) && is_int($count)) {
                    $evaluationsReportedByContextId[$contextId] = ($evaluationsReportedByContextId[$contextId] ?? 0)
                        + $count;
                }
            }
        }
        ksort($evaluationsReportedByContextId);

        return $evaluationsReportedByContextId;
    }

    /**
     * @param list<RecordedRequest> $reports
     *
     * @return list<mixed>
     */
    private static function evaluationsReportedAsDroppedIn(array $reports): array
    {
        return array_map(
            static function (array $report): mixed {
                $droppedEvents = is_array($report['body']) ? $report['body']['droppedEvents'] ?? null : null;

                return is_array($droppedEvents) ? $droppedEvents['evaluatedConfig'] ?? null : null;
            },
            $reports,
        );
    }
}
