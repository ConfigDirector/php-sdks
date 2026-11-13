<?php

declare(strict_types=1);

namespace ConfigDirector\Tests\Internal\Polling;

use ConfigDirector\Internal\LocalStore\LocalStore;
use ConfigDirector\Tests\Support\PhpProcess;
use ConfigDirector\Tests\Support\RecordingLogger;
use ConfigDirector\Tests\Support\ScriptedSdkServer;
use ConfigDirector\Tests\Support\TemporaryFolder;
use PHPUnit\Framework\TestCase;

/**
 * @phpstan-import-type ScriptedAnswer from ScriptedSdkServer
 */
final class PollingRequestsTest extends TestCase
{
    private const int NOVEMBER_12_2026_AT_NOON = 1_794_484_800_000;

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

    public function testTheFirstRequestOfAHostPollsOnceWhileItInitializesAndFillsTheStore(): void
    {
        $server = $this->serverAnswering([
            ['status' => 200, 'body' => ['kind' => 'full', 'configs' => ['flag' => ['value' => 'served']]]],
        ]);

        $output = $this->outputOfARequestAt(self::NOVEMBER_12_2026_AT_NOON);

        self::assertSame("ready\n", $output);
        self::assertCount(1, $server->requestsReceived());
        self::assertSame(
            ['kind' => 'full', 'configs' => ['flag' => ['value' => 'served']]],
            LocalStore::ofServerSdkKey('server-sdk-key', $this->storagePath, new RecordingLogger())
                ->storedPayload()
                ?->payload,
        );
    }

    public function testTwentyRequestsInsideThePollingIntervalSendNoPoll(): void
    {
        $server = $this->serverAnswering([
            ['status' => 200, 'body' => ['kind' => 'full', 'configs' => []]],
            ['status' => 200, 'body' => ['kind' => 'full', 'configs' => []]],
        ]);
        $this->outputOfARequestAt(self::NOVEMBER_12_2026_AT_NOON);

        $outputs = [];
        for ($request = 1; $request <= 20; $request++) {
            $outputs[] = $this->outputOfARequestAt(self::NOVEMBER_12_2026_AT_NOON + 59_999);
        }

        self::assertSame(array_fill(0, 20, "ready\n"), $outputs);
        self::assertCount(1, $server->requestsReceived());
    }

    public function testTheFirstRequestAfterThePollingIntervalPollsOnlyAfterItsOwnOutput(): void
    {
        $server = $this->serverAnswering([
            ['status' => 200, 'body' => ['kind' => 'full', 'configs' => []]],
            ['status' => 200, 'body' => ['kind' => 'full', 'configs' => []]],
        ]);
        $this->outputOfARequestAt(self::NOVEMBER_12_2026_AT_NOON);
        $fileAwaitedBeforeEnding = $this->storagePath . DIRECTORY_SEPARATOR . 'end-the-request';

        $request = $this->requestAt(self::NOVEMBER_12_2026_AT_NOON + 60_000, $fileAwaitedBeforeEnding);
        $output = $request->nextLineOfOutput();
        $pollsReceivedByTheTimeOfTheOutput = count($server->requestsReceived());
        touch($fileAwaitedBeforeEnding);
        $request->finished();

        self::assertSame('ready', $output);
        self::assertSame(1, $pollsReceivedByTheTimeOfTheOutput);
        self::assertCount(2, $server->requestsReceived());
    }

    public function testTheFirstRequestAfterThePollingIntervalPollsUnderTheSessionIdOfTheFirstPoll(): void
    {
        $server = $this->serverAnswering([
            ['status' => 200, 'body' => ['kind' => 'full', 'configs' => []]],
            ['status' => 200, 'body' => ['kind' => 'full', 'configs' => []]],
        ]);
        $this->outputOfARequestAt(self::NOVEMBER_12_2026_AT_NOON);

        $this->outputOfARequestAt(self::NOVEMBER_12_2026_AT_NOON + 60_000);

        [$firstPoll, $secondPoll] = $server->requestsReceived();
        self::assertIsArray($firstPoll['body']);
        self::assertIsArray($secondPoll['body']);
        self::assertIsString($firstPoll['body']['sessionId']);
        self::assertSame($firstPoll['body']['sessionId'], $secondPoll['body']['sessionId']);
    }

    public function testOnlyTheFirstOfTwoRequestsAfterThePollingIntervalPolls(): void
    {
        $server = $this->serverAnswering([
            ['status' => 200, 'body' => ['kind' => 'full', 'configs' => []]],
            ['status' => 200, 'body' => ['kind' => 'full', 'configs' => []]],
            ['status' => 200, 'body' => ['kind' => 'full', 'configs' => []]],
        ]);
        $this->outputOfARequestAt(self::NOVEMBER_12_2026_AT_NOON);
        $this->outputOfARequestAt(self::NOVEMBER_12_2026_AT_NOON + 60_000);

        $this->outputOfARequestAt(self::NOVEMBER_12_2026_AT_NOON + 60_001);

        self::assertCount(2, $server->requestsReceived());
    }

    public function testTenRequestsThatFindTheStoreStaleAndEndAtOnceSendOnePoll(): void
    {
        $server = $this->serverAnswering([
            ['status' => 200, 'body' => ['kind' => 'full', 'configs' => []]],
            ['status' => 200, 'body' => ['kind' => 'full', 'configs' => []], 'delayMilliseconds' => 300],
            ['status' => 200, 'body' => ['kind' => 'full', 'configs' => []]],
        ]);
        $this->outputOfARequestAt(self::NOVEMBER_12_2026_AT_NOON);
        $fileAwaitedBeforeEnding = $this->storagePath . DIRECTORY_SEPARATOR . 'end-the-requests';
        $requests = [];
        for ($request = 1; $request <= 10; $request++) {
            $requests[] = $this->requestAt(self::NOVEMBER_12_2026_AT_NOON + 60_000, $fileAwaitedBeforeEnding);
        }
        $outputs = array_map(static fn(PhpProcess $request): string => $request->nextLineOfOutput(), $requests);

        touch($fileAwaitedBeforeEnding);
        foreach ($requests as $request) {
            $request->finished();
        }

        self::assertSame(array_fill(0, 10, 'ready'), $outputs);
        self::assertCount(2, $server->requestsReceived());
    }

    public function testWhileARequestWaitsForTheAnswerToItsPollTheStoreHoldsAPollingPause(): void
    {
        $server = $this->serverAnswering([
            ['status' => 200, 'body' => ['kind' => 'full', 'configs' => []], 'delayMilliseconds' => 1_000],
        ]);
        $request = $this->requestAt(self::NOVEMBER_12_2026_AT_NOON, '');
        $giveUpAt = hrtime(true) + 10_000_000_000;
        while ($server->requestsReceived() === [] && hrtime(true) < $giveUpAt) {
            usleep(1_000);
        }

        $pollingPauseWhileThePollIsUnanswered = LocalStore::ofServerSdkKey(
            'server-sdk-key',
            $this->storagePath,
            new RecordingLogger(),
        )->pollingPause($server->url . '/server/polling/v1');
        $request->finished();

        self::assertCount(1, $server->requestsReceived());
        self::assertSame(
            self::NOVEMBER_12_2026_AT_NOON + 60_000,
            $pollingPauseWhileThePollIsUnanswered?->untilEpochMilliseconds,
        );
        self::assertFalse($pollingPauseWhileThePollIsUnanswered->pollFailed);
    }

    public function testARequestWhosePollIsRefusedWith401LogsOneErrorAndCallsItsConnectionErrorHandler(): void
    {
        $this->serverAnswering([['status' => 401, 'body' => 'Unauthorized']]);

        $output = $this->outputOfARequestAt(self::NOVEMBER_12_2026_AT_NOON);

        self::assertSame("error-logged 401\nconnection-error 401\nnot-ready\n", $output);
    }

    public function testARequestThatEndsOnARefusedPollCallsItsConnectionErrorHandlerAfterItsOutput(): void
    {
        $this->serverAnswering([
            ['status' => 200, 'body' => ['kind' => 'full', 'configs' => []]],
            ['status' => 401, 'body' => 'Unauthorized'],
        ]);
        $this->outputOfARequestAt(self::NOVEMBER_12_2026_AT_NOON);

        $output = $this->outputOfARequestAt(self::NOVEMBER_12_2026_AT_NOON + 60_000);

        self::assertSame("ready\nerror-logged 401\nconnection-error 401\n", $output);
    }

    public function testAfterA401NoProcessOfTheHostPollsBeforeOneHourHasPassed(): void
    {
        $server = $this->serverAnswering([
            ['status' => 401, 'body' => 'Unauthorized'],
            ['status' => 200, 'body' => ['kind' => 'full', 'configs' => []]],
        ]);
        $this->outputOfARequestAt(self::NOVEMBER_12_2026_AT_NOON);

        $output = $this->outputOfARequestAt(self::NOVEMBER_12_2026_AT_NOON + 3_599_999);

        self::assertSame("not-ready\n", $output);
        self::assertCount(1, $server->requestsReceived());
    }

    public function testAfterA401TheFirstRequestAfterOneHourPollsAgain(): void
    {
        $server = $this->serverAnswering([
            ['status' => 401, 'body' => 'Unauthorized'],
            ['status' => 200, 'body' => ['kind' => 'full', 'configs' => []]],
        ]);
        $this->outputOfARequestAt(self::NOVEMBER_12_2026_AT_NOON);

        $output = $this->outputOfARequestAt(self::NOVEMBER_12_2026_AT_NOON + 3_600_000);

        self::assertSame("ready\n", $output);
        self::assertCount(2, $server->requestsReceived());
    }

    public function testAfterA429WithARetryAfterOfTwoMinutesNoProcessOfTheHostPollsBeforeTwoMinutesHavePassed(): void
    {
        $server = $this->serverAnswering([
            ['status' => 429, 'headers' => ['Retry-After' => '120'], 'body' => 'Service suspended'],
            ['status' => 200, 'body' => ['kind' => 'full', 'configs' => []]],
        ]);
        $this->outputOfARequestAt(self::NOVEMBER_12_2026_AT_NOON);

        $output = $this->outputOfARequestAt(self::NOVEMBER_12_2026_AT_NOON + 119_999);

        self::assertSame("not-ready\n", $output);
        self::assertCount(1, $server->requestsReceived());
    }

    public function testAfterA429WithARetryAfterOfTwoMinutesTheFirstRequestAfterTwoMinutesPollsAgain(): void
    {
        $server = $this->serverAnswering([
            ['status' => 429, 'headers' => ['Retry-After' => '120'], 'body' => 'Service suspended'],
            ['status' => 200, 'body' => ['kind' => 'full', 'configs' => []]],
        ]);
        $this->outputOfARequestAt(self::NOVEMBER_12_2026_AT_NOON);

        $output = $this->outputOfARequestAt(self::NOVEMBER_12_2026_AT_NOON + 120_000);

        self::assertSame("ready\n", $output);
        self::assertCount(2, $server->requestsReceived());
    }

    /**
     * @param list<ScriptedAnswer> $answersInOrder
     */
    private function serverAnswering(array $answersInOrder): ScriptedSdkServer
    {
        return $this->server = ScriptedSdkServer::answering($answersInOrder);
    }

    private function outputOfARequestAt(int $nowEpochMilliseconds): string
    {
        return $this->requestAt($nowEpochMilliseconds, '')->finished()->output;
    }

    private function requestAt(int $nowEpochMilliseconds, string $fileAwaitedBeforeEnding): PhpProcess
    {
        return PhpProcess::running(
            __DIR__ . '/Processes/serve-a-request.php',
            [
                $this->storagePath,
                $this->server->url ?? '',
                (string) $nowEpochMilliseconds,
                $fileAwaitedBeforeEnding,
            ],
        );
    }
}
