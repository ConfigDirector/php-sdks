<?php

declare(strict_types=1);

namespace ConfigDirector\Tests\Internal\Streaming;

use ConfigDirector\ConfigDirectorConnectionException;
use ConfigDirector\ConfigDirectorException;
use ConfigDirector\ConnectionOptions;
use ConfigDirector\Context;
use ConfigDirector\Internal\Client\DefaultConfigDirectorClient;
use ConfigDirector\Internal\Client\EventHandlers;
use ConfigDirector\Internal\Connection\LocalStoreConnection;
use ConfigDirector\Internal\Identity\SdkIdentity;
use ConfigDirector\Internal\LocalStore\LocalStore;
use ConfigDirector\Internal\LocalStore\LocalStoreOpenedOnFirstUse;
use ConfigDirector\Internal\LocalStore\StoredPayload;
use ConfigDirector\Internal\Polling\PollingEndpoint;
use ConfigDirector\Internal\Polling\PollingInterval;
use ConfigDirector\Internal\Polling\PollingTransport;
use ConfigDirector\Internal\Streaming\DefaultStreamingProcess;
use ConfigDirector\Internal\Streaming\StreamingProcessFactory;
use ConfigDirector\Internal\Telemetry\EventReporter;
use ConfigDirector\Internal\Telemetry\LocalStoreTelemetry;
use ConfigDirector\Internal\Telemetry\TelemetryEndpoint;
use ConfigDirector\Internal\Time\ClientLifetime;
use ConfigDirector\Metadata;
use ConfigDirector\TelemetryOptions;
use ConfigDirector\Tests\Support\PhpProcess;
use ConfigDirector\Tests\Support\RecordingLogger;
use ConfigDirector\Tests\Support\ScriptedStreamServer;
use ConfigDirector\Tests\Support\SettableClock;
use ConfigDirector\Tests\Support\TemporaryFolder;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;

/**
 * @phpstan-import-type ScriptedStream from ScriptedStreamServer
 */
final class StreamingProcessOverTimeTest extends TestCase
{
    private const int NOVEMBER_13_2026_AT_NOON = 1_794_571_200_000;

    private const int ONE_HOUR_MILLISECONDS = 3_600_000;

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

    private const array BANNER = [
        'id' => 'config-banner',
        'key' => 'banner',
        'type' => 'string',
        'target' => ['rules' => [], 'defaultValue' => 'Welcome', 'defaultValueId' => 'value-welcome'],
    ];

    private string $storagePath;

    private SettableClock $clock;

    private RecordingLogger $logger;

    private float $randomFraction = 0.0;

    private ?ScriptedStreamServer $server = null;

    private ?DefaultStreamingProcess $streamingProcess = null;

    #[\Override]
    protected function setUp(): void
    {
        $this->storagePath = TemporaryFolder::create();
        $this->clock = new SettableClock(self::NOVEMBER_13_2026_AT_NOON);
        $this->logger = new RecordingLogger();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->streamingProcess?->letGoOfTheLocalStore();
        $this->server?->stop();
        TemporaryFolder::remove($this->storagePath);
    }

    public function testAStreamThatCannotBeOpenedIsTriedAgainAfterHalfOfTwoToTheAttemptSecondsWhenTheRandomShareIsNothing(): void
    {
        $server = $this->serverStreaming([['status' => 500], ['status' => 500], ['status' => 500]]);
        $this->randomFraction = 0.0;
        $streamingProcess = $this->streamingProcessHoldingTheLocalStore();
        $this->advanceUntil($streamingProcess, fn(): bool => count($this->reconnectionsLoggedAt(LogLevel::INFO)) === 1);

        $this->clock->epochMilliseconds += 999;
        $this->advanceForRealMilliseconds($streamingProcess, 30);
        $streamRequestsOneMillisecondBeforeOneSecond = count($server->requestsReceivedAt('/server/sse/v1'));
        $this->clock->epochMilliseconds += 1;
        $this->advanceUntil($streamingProcess, fn(): bool => count($this->reconnectionsLoggedAt(LogLevel::INFO)) === 2);
        $this->clock->epochMilliseconds += 1_999;
        $this->advanceForRealMilliseconds($streamingProcess, 30);
        $streamRequestsOneMillisecondBeforeTwoMoreSeconds = count($server->requestsReceivedAt('/server/sse/v1'));
        $this->clock->epochMilliseconds += 1;
        $this->advanceUntil($streamingProcess, fn(): bool => count($this->reconnectionsLoggedAt(LogLevel::INFO)) === 3);

        self::assertSame(1, $streamRequestsOneMillisecondBeforeOneSecond);
        self::assertSame(2, $streamRequestsOneMillisecondBeforeTwoMoreSeconds);
        self::assertCount(3, $server->requestsReceivedAt('/server/sse/v1'));
    }

    public function testAStreamThatCannotBeOpenedIsTriedAgainAfterAlmostTwoToTheAttemptSecondsWhenTheRandomShareIsAlmostWhole(): void
    {
        $server = $this->serverStreaming([['status' => 500], ['status' => 500]]);
        $this->randomFraction = 0.999;
        $streamingProcess = $this->streamingProcessHoldingTheLocalStore();
        $this->advanceUntil($streamingProcess, fn(): bool => count($this->reconnectionsLoggedAt(LogLevel::INFO)) === 1);

        $this->clock->epochMilliseconds += 1_998;
        $this->advanceForRealMilliseconds($streamingProcess, 30);
        $streamRequestsOneMillisecondEarly = count($server->requestsReceivedAt('/server/sse/v1'));
        $this->clock->epochMilliseconds += 1;
        $this->advanceUntil($streamingProcess, fn(): bool => count($this->reconnectionsLoggedAt(LogLevel::INFO)) === 2);

        self::assertSame(1, $streamRequestsOneMillisecondEarly);
        self::assertCount(2, $server->requestsReceivedAt('/server/sse/v1'));
    }

    public function testTheDelayDoublesWithEachFailedAttemptUpToTwoToTheNinthSeconds(): void
    {
        $this->serverStreaming(array_fill(0, 11, ['status' => 500]));
        $this->randomFraction = 0.0;
        $streamingProcess = $this->streamingProcessHoldingTheLocalStore();

        for ($attempt = 1; $attempt <= 11; $attempt++) {
            $this->advanceUntil(
                $streamingProcess,
                fn(): bool => count($this->reconnectionsLogged()) === $attempt,
            );
            $this->clock->epochMilliseconds += 256_000;
        }

        self::assertSame(
            [1_000, 2_000, 4_000, 8_000, 16_000, 32_000, 64_000, 128_000, 256_000, 256_000, 256_000],
            array_column($this->reconnectionsLogged(), 'delay'),
        );
    }

    public function testTheFirstFiveReconnectionsAreLoggedAtInfoAndTheLaterOnesAsWarnings(): void
    {
        $this->serverStreaming(array_fill(0, 7, ['status' => 500]));
        $streamingProcess = $this->streamingProcessHoldingTheLocalStore();

        for ($attempt = 1; $attempt <= 7; $attempt++) {
            $this->advanceUntil(
                $streamingProcess,
                fn(): bool => count($this->reconnectionsLogged()) === $attempt,
            );
            $this->clock->epochMilliseconds += 64_000;
        }

        self::assertSame([1, 2, 3, 4, 5], array_column($this->reconnectionsLoggedAt(LogLevel::INFO), 'attempt'));
        self::assertSame([6, 7], array_column($this->reconnectionsLoggedAt(LogLevel::WARNING), 'attempt'));
    }

    public function testTheAttemptCountStartsOverOnceAStreamHasOpened(): void
    {
        $this->serverStreaming([
            ['status' => 500],
            ['status' => 500],
            ['chunks' => [self::eventOfTheFullPayloadWith(self::PAGE_SIZE_OF_25)], 'closes' => true],
            ['status' => 500],
        ]);
        $streamingProcess = $this->streamingProcessHoldingTheLocalStore();

        for ($reconnection = 1; $reconnection <= 4; $reconnection++) {
            $this->advanceUntil(
                $streamingProcess,
                fn(): bool => count($this->reconnectionsLogged()) === $reconnection,
            );
            $this->clock->epochMilliseconds += 2_000;
        }

        self::assertSame([1, 2, 1, 2], array_column($this->reconnectionsLogged(), 'attempt'));
    }

    public function testA429IsTriedAgainAfterItsRetryAfterAndNotAfterTheShorterBackoff(): void
    {
        $server = $this->serverStreaming([
            ['status' => 429, 'headers' => ['Retry-After' => '2']],
            ['chunks' => [self::eventOfTheFullPayloadWith(self::PAGE_SIZE_OF_25)]],
        ]);
        $this->randomFraction = 0.0;
        $streamingProcess = $this->streamingProcessHoldingTheLocalStore();
        $this->advanceUntil($streamingProcess, fn(): bool => count($this->reconnectionsLogged()) === 1);

        $this->clock->epochMilliseconds += 1_999;
        $this->advanceForRealMilliseconds($streamingProcess, 30);
        $streamRequestsOneMillisecondEarly = count($server->requestsReceivedAt('/server/sse/v1'));
        $this->clock->epochMilliseconds += 1;
        $this->advanceUntil($streamingProcess, fn(): bool => $this->storedPayload() !== null);

        self::assertSame(1, $streamRequestsOneMillisecondEarly);
        self::assertCount(2, $server->requestsReceivedAt('/server/sse/v1'));
        self::assertSame([], $this->logger->contextsLoggedAt(LogLevel::ERROR));
    }

    public function testARetryAfterIsCountedForAtMostOneHour(): void
    {
        $server = $this->serverStreaming([
            ['status' => 429, 'headers' => ['Retry-After' => '7200']],
            ['chunks' => [self::eventOfTheFullPayloadWith(self::PAGE_SIZE_OF_25)]],
        ]);
        $streamingProcess = $this->streamingProcessHoldingTheLocalStore();
        $this->advanceUntil($streamingProcess, fn(): bool => count($this->reconnectionsLogged()) === 1);

        $this->clock->epochMilliseconds += self::ONE_HOUR_MILLISECONDS - 1;
        $this->advanceForRealMilliseconds($streamingProcess, 30);
        $streamRequestsOneMillisecondBeforeTheHour = count($server->requestsReceivedAt('/server/sse/v1'));
        $this->clock->epochMilliseconds += 1;
        $this->advanceUntil($streamingProcess, fn(): bool => $this->storedPayload() !== null);

        self::assertSame(1, $streamRequestsOneMillisecondBeforeTheHour);
        self::assertCount(2, $server->requestsReceivedAt('/server/sse/v1'));
    }

    public function testA503IsTriedAgainAfterTheBackoffWhateverItsRetryAfter(): void
    {
        $server = $this->serverStreaming([
            ['status' => 503, 'headers' => ['Retry-After' => '120']],
            ['chunks' => [self::eventOfTheFullPayloadWith(self::PAGE_SIZE_OF_25)]],
        ]);
        $this->randomFraction = 0.0;
        $streamingProcess = $this->streamingProcessHoldingTheLocalStore();
        $this->advanceUntil($streamingProcess, fn(): bool => count($this->reconnectionsLogged()) === 1);

        $this->clock->epochMilliseconds += 1_000;
        $this->advanceUntil($streamingProcess, fn(): bool => $this->storedPayload() !== null);

        self::assertCount(2, $server->requestsReceivedAt('/server/sse/v1'));
    }

    public function testARedirectIsNotFollowedAndCountsAsAFailedAttempt(): void
    {
        $server = $this->serverStreaming([
            ['status' => 301, 'headers' => ['Location' => '/elsewhere']],
            ['status' => 301, 'headers' => ['Location' => '/elsewhere']],
        ]);
        $streamingProcess = $this->streamingProcessHoldingTheLocalStore();
        $this->advanceUntil($streamingProcess, fn(): bool => count($this->reconnectionsLogged()) === 1);

        $this->clock->epochMilliseconds += 2_000;
        $this->advanceUntil($streamingProcess, fn(): bool => count($this->reconnectionsLogged()) === 2);

        self::assertSame([1, 2], array_column($this->reconnectionsLogged(), 'attempt'));
        self::assertSame([], $server->requestsReceivedAt('/elsewhere'));
    }

    public function testADroppedStreamThatNamedARetryTimeIsOpenedAgainAfterThatTime(): void
    {
        $server = $this->serverStreaming([
            ['chunks' => ["retry: 3000\n\n", self::eventOfTheFullPayloadWith(self::PAGE_SIZE_OF_25)], 'closes' => true],
            ['chunks' => [self::eventOfTheFullPayloadWith(self::PAGE_SIZE_OF_50)]],
        ]);
        $this->randomFraction = 0.0;
        $streamingProcess = $this->streamingProcessHoldingTheLocalStore();
        $this->advanceUntil($streamingProcess, fn(): bool => count($this->reconnectionsLogged()) === 1);

        $this->clock->epochMilliseconds += 2_999;
        $this->advanceForRealMilliseconds($streamingProcess, 30);
        $streamRequestsOneMillisecondEarly = count($server->requestsReceivedAt('/server/sse/v1'));
        $this->clock->epochMilliseconds += 1;
        $this->advanceUntil(
            $streamingProcess,
            static fn(): bool => count($server->requestsReceivedAt('/server/sse/v1')) === 2,
        );

        self::assertSame(1, $streamRequestsOneMillisecondEarly);
    }

    public function testAConnectionThatGetsNoAnswerWithinThirtySecondsCountsAsAFailedAttempt(): void
    {
        $this->serverStreaming([['holdsWithoutAnswering' => true]]);
        $streamingProcess = $this->streamingProcessHoldingTheLocalStore();
        $this->advanceUntil(
            $streamingProcess,
            fn(): bool => count($this->server?->requestsReceivedAt('/server/sse/v1') ?? []) === 1,
        );

        $this->clock->epochMilliseconds += 29_999;
        $streamingProcess->advance();
        $reconnectionsOneMillisecondBeforeThirtySeconds = $this->reconnectionsLogged();
        $this->clock->epochMilliseconds += 1;
        $streamingProcess->advance();

        self::assertSame([], $reconnectionsOneMillisecondBeforeThirtySeconds);
        self::assertSame([1], array_column($this->reconnectionsLogged(), 'attempt'));
    }

    public function testAnOpenStreamThatCarriesNoByteForFortyFiveSecondsIsDroppedAndOpenedAgain(): void
    {
        $this->serverStreaming([['chunks' => [self::eventOfTheFullPayloadWith(self::PAGE_SIZE_OF_25)]]]);
        $streamingProcess = $this->streamingProcessHoldingTheLocalStore();
        $this->advanceUntil($streamingProcess, fn(): bool => $this->storedPayload() !== null);

        $this->clock->epochMilliseconds += 44_999;
        $streamingProcess->advance();
        $reconnectionsOneMillisecondBeforeFortyFiveSeconds = $this->reconnectionsLogged();
        $this->clock->epochMilliseconds += 1;
        $streamingProcess->advance();

        self::assertSame([], $reconnectionsOneMillisecondBeforeFortyFiveSeconds);
        self::assertSame([1], array_column($this->reconnectionsLogged(), 'attempt'));
    }

    public function testAStreamDroppedForItsSilenceIsClosedSoTheSdkServerNoLongerCountsIt(): void
    {
        $server = $this->serverStreaming([['chunks' => [self::eventOfTheFullPayloadWith(self::PAGE_SIZE_OF_25)]]]);
        $streamingProcess = $this->streamingProcessHoldingTheLocalStore();
        $this->advanceUntil($streamingProcess, fn(): bool => $this->storedPayload() !== null);
        $streamsClosedWhileItWasOpen = $server->streamsClosedByTheClient();

        $this->clock->epochMilliseconds += 45_000;
        $this->advanceUntil($streamingProcess, static fn(): bool => $server->streamsClosedByTheClient() === 1);

        self::assertSame(0, $streamsClosedWhileItWasOpen);
    }

    public function testLettingGoOfTheLocalStoreClosesTheStream(): void
    {
        $server = $this->serverStreaming([['chunks' => [self::eventOfTheFullPayloadWith(self::PAGE_SIZE_OF_25)]]]);
        $streamingProcess = $this->streamingProcessHoldingTheLocalStore();
        $this->advanceUntil($streamingProcess, fn(): bool => $this->storedPayload() !== null);

        $streamingProcess->letGoOfTheLocalStore();
        $deadline = hrtime(true) + 5_000_000_000;
        while ($server->streamsClosedByTheClient() === 0 && hrtime(true) < $deadline) {
            usleep(1_000);
        }

        self::assertSame(1, $server->streamsClosedByTheClient());
    }

    public function testAStreamThatCarriesOnlyKeepaliveCommentsIsNotDropped(): void
    {
        $server = $this->serverStreaming([['chunks' => [self::eventOfTheFullPayloadWith(self::PAGE_SIZE_OF_25)]]]);
        $streamingProcess = $this->streamingProcessHoldingTheLocalStore();
        $this->advanceUntil($streamingProcess, fn(): bool => $this->storedPayload() !== null);

        $this->clock->epochMilliseconds += 40_000;
        $server->sendToTheOpenStream(":\n\n");
        $this->advanceForRealMilliseconds($streamingProcess, 500);
        $this->clock->epochMilliseconds += 40_000;
        $streamingProcess->advance();

        self::assertSame([], $this->reconnectionsLogged());
        self::assertCount(1, $server->requestsReceivedAt('/server/sse/v1'));
    }

    public function testAHeartbeatCarryingTheSessionIdOfTheStreamIsSentEveryNinetySecondsWhileTheStreamIsOpen(): void
    {
        $server = $this->serverStreaming([['chunks' => [self::eventOfTheFullPayloadWith(self::PAGE_SIZE_OF_25)]]]);
        $streamingProcess = $this->streamingProcessHoldingTheLocalStore();
        $this->advanceUntil($streamingProcess, fn(): bool => $this->storedPayload() !== null);

        $this->advanceTheClockWhileTheStreamShowsSignsOfLife($streamingProcess, 89_999);
        $this->advanceForRealMilliseconds($streamingProcess, 30);
        $heartbeatsOneMillisecondBeforeNinetySeconds = $server->requestsReceivedAt('/server/heartbeat/v1');
        $this->clock->epochMilliseconds += 1;
        $this->advanceUntil(
            $streamingProcess,
            static fn(): bool => count($server->requestsReceivedAt('/server/heartbeat/v1')) === 1,
        );
        $this->advanceTheClockWhileTheStreamShowsSignsOfLife($streamingProcess, 90_000);
        $this->advanceUntil(
            $streamingProcess,
            static fn(): bool => count($server->requestsReceivedAt('/server/heartbeat/v1')) === 2,
        );

        [$streamRequest] = $server->requestsReceivedAt('/server/sse/v1');
        [$firstHeartbeat, $secondHeartbeat] = $server->requestsReceivedAt('/server/heartbeat/v1');
        self::assertSame([], $heartbeatsOneMillisecondBeforeNinetySeconds);
        self::assertIsArray($streamRequest['body']);
        self::assertSame('POST', $firstHeartbeat['method']);
        self::assertSame('application/json', $firstHeartbeat['headers']['content-type']);
        self::assertSame(
            ['serverSdkKey' => 'server-sdk-key', 'sessionId' => $streamRequest['body']['sessionId']],
            $firstHeartbeat['body'],
        );
        self::assertSame($firstHeartbeat['body'], $secondHeartbeat['body']);
    }

    public function testARefusedHeartbeatLeavesTheStreamOpenAndIsLoggedBelowInfo(): void
    {
        $server = $this->serverStreaming(
            [['chunks' => [self::eventOfTheFullPayloadWith(self::PAGE_SIZE_OF_25)]]],
            ['/server/heartbeat/v1' => ['status' => 500]],
        );
        $streamingProcess = $this->streamingProcessHoldingTheLocalStore();
        $this->advanceUntil($streamingProcess, fn(): bool => $this->storedPayload() !== null);
        $this->advanceTheClockWhileTheStreamShowsSignsOfLife($streamingProcess, 90_000);
        $this->advanceUntil(
            $streamingProcess,
            fn(): bool => in_array(['status' => 500], $this->logger->contextsLoggedAt(LogLevel::DEBUG), true),
        );

        $server->sendToTheOpenStream(self::eventOfTheFullPayloadWith(self::PAGE_SIZE_OF_50));
        $this->advanceUntil($streamingProcess, fn(): bool => $this->storedPageSize() === '50');

        self::assertCount(1, $server->requestsReceivedAt('/server/sse/v1'));
        self::assertSame([], $this->reconnectionsLogged());
        self::assertSame([], $this->logger->contextsLoggedAt(LogLevel::WARNING));
    }

    public function testNoHeartbeatIsSentWhileTheStreamIsDown(): void
    {
        $server = $this->serverStreaming([
            ['chunks' => [self::eventOfTheFullPayloadWith(self::PAGE_SIZE_OF_25)]],
            ['status' => 500],
        ]);
        $streamingProcess = $this->streamingProcessHoldingTheLocalStore();
        $this->advanceUntil($streamingProcess, fn(): bool => $this->storedPayload() !== null);
        $server->closeTheOpenStream();
        $this->advanceUntil($streamingProcess, fn(): bool => count($this->reconnectionsLogged()) === 1);

        $this->clock->epochMilliseconds += 90_000;
        $this->advanceUntil($streamingProcess, fn(): bool => count($this->reconnectionsLogged()) === 2);
        $this->advanceForRealMilliseconds($streamingProcess, 30);

        self::assertSame([], $server->requestsReceivedAt('/server/heartbeat/v1'));
    }

    public function testADeltaThatArrivesWhileTheLocalStoreHoldsNothingIsNotStoredAndTheStreamIsOpenedAgain(): void
    {
        $server = $this->serverStreaming([
            ['chunks' => [ScriptedStreamServer::eventCarrying(
                ['kind' => 'delta', 'configs' => ['page-size' => self::PAGE_SIZE_OF_50]],
            )]],
            ['chunks' => [self::eventOfTheFullPayloadWith(self::PAGE_SIZE_OF_25)]],
        ]);
        $streamingProcess = $this->streamingProcessHoldingTheLocalStore();

        $this->advanceUntil($streamingProcess, fn(): bool => count($this->reconnectionsLogged()) === 1);
        $payloadStoredFromTheDelta = $this->storedPayload();
        $this->clock->epochMilliseconds += 1_000;
        $this->advanceUntil($streamingProcess, fn(): bool => $this->storedPageSize() === '25');

        self::assertNull($payloadStoredFromTheDelta);
        self::assertCount(2, $server->requestsReceivedAt('/server/sse/v1'));
    }

    public function testAPayloadThatArrivesWhileARequestHoldsTheRefreshLockIsStoredOnceTheLockIsFree(): void
    {
        $server = $this->serverStreaming([['chunks' => [self::eventOfTheFullPayloadWith(self::PAGE_SIZE_OF_25)]]]);
        $streamingProcess = $this->streamingProcessHoldingTheLocalStore();
        $this->advanceUntil($streamingProcess, fn(): bool => $this->storedPayload() !== null);
        $processHoldingTheRefreshLock = PhpProcess::running(
            dirname(__DIR__) . '/LocalStore/Processes/keep-the-refresh-lock.php',
            [$this->storagePath, 'server-sdk-key', '400'],
        );
        $lineOfTheHolder = $processHoldingTheRefreshLock->nextLineOfOutput();

        $server->sendToTheOpenStream(self::eventOfTheFullPayloadWith(self::PAGE_SIZE_OF_50));
        $this->advanceUntil($streamingProcess, fn(): bool => $this->storedPageSize() === '50');
        $processHoldingTheRefreshLock->finished();

        self::assertSame('locked', $lineOfTheHolder);
        self::assertSame([], $this->reconnectionsLogged());
        self::assertSame([], $this->logger->contextsLoggedAt(LogLevel::WARNING));
    }

    public function testADeltaIsMergedIntoTheStoredPayloadSoTheConfigsItDoesNotNameStay(): void
    {
        $server = $this->serverStreaming([['chunks' => [self::eventOfTheFullPayloadWithThePageSizeAndTheBanner()]]]);
        $streamingProcess = $this->streamingProcessHoldingTheLocalStore();
        $this->advanceUntil($streamingProcess, fn(): bool => $this->storedPayload() !== null);

        $server->sendToTheOpenStream(ScriptedStreamServer::eventCarrying(
            ['kind' => 'delta', 'configs' => ['page-size' => self::PAGE_SIZE_OF_50]],
        ));
        $this->advanceUntil($streamingProcess, fn(): bool => $this->storedPageSize() === '50');

        self::assertSame(['page-size', 'banner'], $this->storedConfigKeys());
    }

    public function testAFullPayloadReplacesTheStoredPayloadSoTheConfigsItDoesNotNameAreGone(): void
    {
        $server = $this->serverStreaming([['chunks' => [self::eventOfTheFullPayloadWithThePageSizeAndTheBanner()]]]);
        $streamingProcess = $this->streamingProcessHoldingTheLocalStore();
        $this->advanceUntil($streamingProcess, fn(): bool => $this->storedPayload() !== null);

        $server->sendToTheOpenStream(self::eventOfTheFullPayloadWith(self::PAGE_SIZE_OF_50));
        $this->advanceUntil($streamingProcess, fn(): bool => $this->storedPageSize() === '50');

        self::assertSame(['page-size'], $this->storedConfigKeys());
    }

    public function testTheLocalStoreKeepsTheSessionIdOfItsPollsWhenTheStreamWritesToIt(): void
    {
        $this->serverStreaming([['chunks' => [self::eventOfTheFullPayloadWith(self::PAGE_SIZE_OF_50)]]]);
        $refreshLock = $this->localStore()->refreshLockAcquiredWithin(0);
        $refreshLock?->replaceStoredPayload(new StoredPayload(
            ['kind' => 'full', 'configs' => ['page-size' => self::PAGE_SIZE_OF_25]],
            $this->clock->epochMilliseconds,
            'session-of-the-polls',
        ));
        $refreshLock?->release();
        $streamingProcess = $this->streamingProcessHoldingTheLocalStore();

        $this->advanceUntil($streamingProcess, fn(): bool => $this->storedPageSize() === '50');

        self::assertSame('session-of-the-polls', $this->storedPayload()?->sessionId);
    }

    public function testAnEmptyLocalStoreGetsASessionIdOfItsOwnAndNotTheOneOfTheStream(): void
    {
        $server = $this->serverStreaming([['chunks' => [self::eventOfTheFullPayloadWith(self::PAGE_SIZE_OF_25)]]]);
        $streamingProcess = $this->streamingProcessHoldingTheLocalStore();

        $this->advanceUntil($streamingProcess, fn(): bool => $this->storedPayload() !== null);

        [$streamRequest] = $server->requestsReceivedAt('/server/sse/v1');
        self::assertIsArray($streamRequest['body']);
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $this->storedPayload()->sessionId ?? '',
        );
        self::assertNotSame($streamRequest['body']['sessionId'], $this->storedPayload()?->sessionId);
    }

    public function testAnEventThatIsNotJsonIsSkippedWithOneErrorAndTheStreamStaysOpen(): void
    {
        $server = $this->serverStreaming([['chunks' => [self::eventOfTheFullPayloadWith(self::PAGE_SIZE_OF_25)]]]);
        $streamingProcess = $this->streamingProcessHoldingTheLocalStore();
        $this->advanceUntil($streamingProcess, fn(): bool => $this->storedPayload() !== null);

        $server->sendToTheOpenStream("data: not json\n\n");
        $server->sendToTheOpenStream(self::eventOfTheFullPayloadWith(self::PAGE_SIZE_OF_50));
        $this->advanceUntil($streamingProcess, fn(): bool => $this->storedPageSize() === '50');

        self::assertCount(1, $this->logger->contextsLoggedAt(LogLevel::ERROR));
        self::assertCount(1, $server->requestsReceivedAt('/server/sse/v1'));
        self::assertSame([], $this->reconnectionsLogged());
    }

    public function testAnEventWithoutAConfigsObjectIsSkippedWithOneErrorAndTheStoredPayloadStays(): void
    {
        $server = $this->serverStreaming([['chunks' => [self::eventOfTheFullPayloadWith(self::PAGE_SIZE_OF_25)]]]);
        $streamingProcess = $this->streamingProcessHoldingTheLocalStore();
        $this->advanceUntil($streamingProcess, fn(): bool => $this->storedPayload() !== null);

        $server->sendToTheOpenStream("data: {\"kind\":\"full\"}\n\n");
        $this->advanceUntil(
            $streamingProcess,
            fn(): bool => count($this->logger->contextsLoggedAt(LogLevel::ERROR)) === 1,
        );

        self::assertSame(['page-size'], $this->storedConfigKeys());
        self::assertCount(1, $server->requestsReceivedAt('/server/sse/v1'));
        self::assertSame([], $this->reconnectionsLogged());
    }

    public function testALineLongerThanOneMebibyteDropsTheStreamAndItIsOpenedAgain(): void
    {
        $server = $this->serverStreaming([
            ['chunks' => [self::eventOfTheFullPayloadWith(self::PAGE_SIZE_OF_25)]],
            ['chunks' => [self::eventOfTheFullPayloadWith(self::PAGE_SIZE_OF_50)]],
        ]);
        $streamingProcess = $this->streamingProcessHoldingTheLocalStore();
        $this->advanceUntil($streamingProcess, fn(): bool => $this->storedPayload() !== null);

        $server->sendToTheOpenStream('data: ' . str_repeat('x', 1_048_577));
        $this->advanceUntil($streamingProcess, fn(): bool => count($this->reconnectionsLogged()) === 1);
        $this->clock->epochMilliseconds += 2_000;
        $this->advanceUntil($streamingProcess, fn(): bool => $this->storedPageSize() === '50');

        self::assertSame([1], array_column($this->reconnectionsLogged(), 'attempt'));
        self::assertCount(2, $server->requestsReceivedAt('/server/sse/v1'));
    }

    public function testAStreamRefusedWithA403ThrowsAConnectionExceptionWithTheStatusAfterOneErrorNamingIt(): void
    {
        $server = $this->serverStreaming([['status' => 403, 'body' => 'The key server-sdk-key is not allowed']]);
        $streamingProcess = $this->streamingProcessHoldingTheLocalStore();

        $thrown = null;
        try {
            $this->advanceUntil($streamingProcess, static fn(): bool => false);
        } catch (ConfigDirectorConnectionException $exception) {
            $thrown = $exception;
        }

        self::assertInstanceOf(ConfigDirectorConnectionException::class, $thrown);
        self::assertSame(403, $thrown->status);
        self::assertStringNotContainsString('server-sdk-key', $thrown->getMessage());
        self::assertSame([['exception' => $thrown]], $this->logger->contextsLoggedAt(LogLevel::ERROR));
        self::assertSame([], $this->reconnectionsLogged());
        self::assertCount(1, $server->requestsReceivedAt('/server/sse/v1'));
    }

    public function testTheStoredPayloadIsConfirmedFreshEveryThirtySecondsWhileTheStreamIsOpen(): void
    {
        $this->serverStreaming([['chunks' => [self::eventOfTheFullPayloadWith(self::PAGE_SIZE_OF_25)]]]);
        $streamingProcess = $this->streamingProcessHoldingTheLocalStore();
        $this->advanceUntil($streamingProcess, fn(): bool => $this->storedPayload() !== null);

        $this->clock->epochMilliseconds += 29_999;
        $streamingProcess->advance();
        $confirmedFreshOneMillisecondBeforeThirtySeconds = $this->storedPayload()?->confirmedFreshAtEpochMilliseconds;
        $this->clock->epochMilliseconds += 1;
        $streamingProcess->advance();

        self::assertSame(self::NOVEMBER_13_2026_AT_NOON, $confirmedFreshOneMillisecondBeforeThirtySeconds);
        self::assertSame(
            self::NOVEMBER_13_2026_AT_NOON + 30_000,
            $this->storedPayload()?->confirmedFreshAtEpochMilliseconds,
        );
        self::assertSame(['page-size'], $this->storedConfigKeys());
    }

    public function testTheStoredPayloadIsNotConfirmedFreshWhileTheStreamIsDown(): void
    {
        $server = $this->serverStreaming([
            ['chunks' => [self::eventOfTheFullPayloadWith(self::PAGE_SIZE_OF_25)]],
            ['status' => 500],
        ]);
        $streamingProcess = $this->streamingProcessHoldingTheLocalStore();
        $this->advanceUntil($streamingProcess, fn(): bool => $this->storedPayload() !== null);
        $server->closeTheOpenStream();
        $this->advanceUntil($streamingProcess, fn(): bool => count($this->reconnectionsLogged()) === 1);

        $this->clock->epochMilliseconds += 30_000;
        $this->advanceUntil($streamingProcess, fn(): bool => count($this->reconnectionsLogged()) === 2);

        self::assertSame(
            self::NOVEMBER_13_2026_AT_NOON,
            $this->storedPayload()?->confirmedFreshAtEpochMilliseconds,
        );
    }

    public function testAPayloadThatCannotBeStoredDropsTheStreamAndTheAttemptCountKeepsGrowingUntilOneIsStored(): void
    {
        $server = $this->serverStreaming([
            ['chunks' => [self::eventOfTheFullPayloadWith(self::PAGE_SIZE_OF_25)]],
            ['chunks' => [self::eventOfTheFullPayloadWith(self::PAGE_SIZE_OF_25)]],
            ['chunks' => [self::eventOfTheFullPayloadWith(self::PAGE_SIZE_OF_50)]],
        ]);
        $streamingProcess = $this->streamingProcessHoldingTheLocalStore();
        $payloadFile = $this->localStoreFolder() . DIRECTORY_SEPARATOR . 'payload.json';
        mkdir($payloadFile);

        $this->advanceUntil($streamingProcess, fn(): bool => count($this->reconnectionsLogged()) === 1);
        $this->clock->epochMilliseconds += 1_000;
        $this->advanceUntil($streamingProcess, fn(): bool => count($this->reconnectionsLogged()) === 2);
        rmdir($payloadFile);
        $this->clock->epochMilliseconds += 2_000;
        $this->advanceUntil($streamingProcess, fn(): bool => $this->storedPageSize() === '50');

        self::assertSame([1, 2], array_column($this->reconnectionsLogged(), 'attempt'));
        self::assertCount(2, $this->logger->contextsLoggedAt(LogLevel::WARNING));
        self::assertCount(3, $server->requestsReceivedAt('/server/sse/v1'));
    }

    public function testAfterAPayloadThatCouldNotBeStoredTheStreamIsOpenedWithoutALastEventId(): void
    {
        $server = $this->serverStreaming([
            ['chunks' => ["id: 9\n" . self::eventOfTheFullPayloadWith(self::PAGE_SIZE_OF_25)]],
            ['chunks' => [self::eventOfTheFullPayloadWith(self::PAGE_SIZE_OF_50)]],
        ]);
        $streamingProcess = $this->streamingProcessHoldingTheLocalStore();
        $payloadFile = $this->localStoreFolder() . DIRECTORY_SEPARATOR . 'payload.json';
        mkdir($payloadFile);
        $this->advanceUntil($streamingProcess, fn(): bool => count($this->reconnectionsLogged()) === 1);
        rmdir($payloadFile);

        $this->clock->epochMilliseconds += 1_000;
        $this->advanceUntil($streamingProcess, fn(): bool => $this->storedPageSize() === '50');

        [, $secondStreamRequest] = $server->requestsReceivedAt('/server/sse/v1');
        self::assertArrayNotHasKey('last-event-id', $secondStreamRequest['headers']);
    }

    public function testAConfirmationThatFindsARequestHoldingTheRefreshLockIsMadeOnceTheLockIsFree(): void
    {
        $this->serverStreaming([['chunks' => [self::eventOfTheFullPayloadWith(self::PAGE_SIZE_OF_25)]]]);
        $streamingProcess = $this->streamingProcessHoldingTheLocalStore();
        $this->advanceUntil($streamingProcess, fn(): bool => $this->storedPayload() !== null);
        $processHoldingTheRefreshLock = PhpProcess::running(
            dirname(__DIR__) . '/LocalStore/Processes/keep-the-refresh-lock.php',
            [$this->storagePath, 'server-sdk-key', '400'],
        );
        $lineOfTheHolder = $processHoldingTheRefreshLock->nextLineOfOutput();

        $this->clock->epochMilliseconds += 30_000;
        $streamingProcess->advance();
        $processHoldingTheRefreshLock->finished();

        self::assertSame('locked', $lineOfTheHolder);
        self::assertSame(
            self::NOVEMBER_13_2026_AT_NOON + 30_000,
            $this->storedPayload()?->confirmedFreshAtEpochMilliseconds,
        );
    }

    public function testA429ThatNamesNoWaitIsTriedAgainAfterOneSecond(): void
    {
        $server = $this->serverStreaming([
            ['status' => 429, 'headers' => ['Retry-After' => '0']],
            ['status' => 429, 'headers' => ['Retry-After' => '0']],
        ]);
        $streamingProcess = $this->streamingProcessHoldingTheLocalStore();
        $this->advanceUntil($streamingProcess, fn(): bool => count($this->reconnectionsLogged()) === 1);

        $this->clock->epochMilliseconds += 999;
        $this->advanceForRealMilliseconds($streamingProcess, 30);
        $streamRequestsOneMillisecondBeforeOneSecond = count($server->requestsReceivedAt('/server/sse/v1'));
        $this->clock->epochMilliseconds += 1;
        $this->advanceUntil($streamingProcess, fn(): bool => count($this->reconnectionsLogged()) === 2);

        self::assertSame(1, $streamRequestsOneMillisecondBeforeOneSecond);
        self::assertCount(2, $server->requestsReceivedAt('/server/sse/v1'));
    }

    public function testEach429InARowCountsAsOneMoreFailedAttempt(): void
    {
        $this->serverStreaming([
            ['status' => 429, 'headers' => ['Retry-After' => '2']],
            ['status' => 429, 'headers' => ['Retry-After' => '2']],
        ]);
        $streamingProcess = $this->streamingProcessHoldingTheLocalStore();
        $this->advanceUntil($streamingProcess, fn(): bool => count($this->reconnectionsLogged()) === 1);

        $this->clock->epochMilliseconds += 2_000;
        $this->advanceUntil($streamingProcess, fn(): bool => count($this->reconnectionsLogged()) === 2);

        self::assertSame([1, 2], array_column($this->reconnectionsLogged(), 'attempt'));
    }

    public function testEachConnectionInARowThatGetsNoAnswerCountsAsOneMoreFailedAttempt(): void
    {
        $server = $this->serverStreaming([['holdsWithoutAnswering' => true], ['holdsWithoutAnswering' => true]]);
        $this->randomFraction = 0.0;
        $streamingProcess = $this->streamingProcessHoldingTheLocalStore();
        $this->advanceUntil(
            $streamingProcess,
            static fn(): bool => count($server->requestsReceivedAt('/server/sse/v1')) === 1,
        );
        $this->clock->epochMilliseconds += 30_000;
        $streamingProcess->advance();
        $this->clock->epochMilliseconds += 1_000;
        $this->advanceUntil(
            $streamingProcess,
            static fn(): bool => count($server->requestsReceivedAt('/server/sse/v1')) === 2,
        );

        $this->clock->epochMilliseconds += 30_000;
        $streamingProcess->advance();

        self::assertSame([1, 2], array_column($this->reconnectionsLogged(), 'attempt'));
    }

    public function testAStreamingProcessWhoseLockFileCannotBeUsedThrowsAfterOneErrorNamingTheFileAndNeverConnects(): void
    {
        $server = $this->serverStreaming([]);
        $lockFile = $this->localStoreFolder() . DIRECTORY_SEPARATOR . 'streaming.lock';
        mkdir($lockFile, recursive: true);

        $thrown = null;
        try {
            $this->streamingProcessHoldingTheLocalStore();
        } catch (ConfigDirectorException $exception) {
            $thrown = $exception;
        }

        self::assertInstanceOf(ConfigDirectorException::class, $thrown);
        self::assertSame([['file' => $lockFile]], $this->logger->contextsLoggedAt(LogLevel::ERROR));
        self::assertSame([], $server->requestsReceived());
    }

    public function testTheLockFileOfARunningStreamingProcessIsTouchedOncePerFlushIntervalSoItNeverLooksAbandoned(): void
    {
        $this->serverStreaming([['chunks' => [self::eventOfTheFullPayloadWith(self::PAGE_SIZE_OF_25)]]]);
        $streamingProcess = $this->streamingProcessHoldingTheLocalStore();
        $this->advanceUntil($streamingProcess, fn(): bool => $this->storedPayload() !== null);
        $lockFile = $this->localStoreFolder() . DIRECTORY_SEPARATOR . 'streaming.lock';
        $oneDayAgo = time() - 86_400;
        touch($lockFile, $oneDayAgo);

        $this->clock->epochMilliseconds += 30_000;
        $streamingProcess->advance();

        clearstatcache(true, $lockFile);
        self::assertGreaterThan($oneDayAgo + 3_600, filemtime($lockFile));
    }

    public function testWhileItRunsNoRequestPollsOrSendsTelemetryAcrossTwoPollingIntervalsAndTheStreamingProcessSendsTheReports(): void
    {
        $server = $this->serverStreaming([['chunks' => [self::eventOfTheFullPayloadWith(self::PAGE_SIZE_OF_25)]]]);
        $streamingProcess = $this->streamingProcessHoldingTheLocalStore();
        $this->advanceUntil($streamingProcess, fn(): bool => $this->storedPayload() !== null);

        $pageSizesRead = [];
        for ($tenSecondStep = 1; $tenSecondStep <= 13; $tenSecondStep++) {
            $this->advanceTheClockWhileTheStreamShowsSignsOfLife($streamingProcess, 10_000);
            $pageSizesRead[] = $this->aRequestReadsThePageSize();
        }

        $reports = $server->requestsReceivedAt('/server/telemetry/v1');
        self::assertSame(array_fill(0, 13, 25), $pageSizesRead);
        self::assertSame([], $server->requestsReceivedAt('/server/polling/v1'));
        self::assertCount(4, $reports);
        self::assertSame(
            ['stream-host', 'stream-host', 'stream-host', 'stream-host'],
            array_column(array_column(array_column($reports, 'body'), 'metaContext'), 'appName'),
        );
        self::assertSame([[2], [3], [3], [3]], array_map(self::evaluationCountsReportedIn(...), $reports));
    }

    public function testAfterItStopsTheFirstRequestPastOnePollingIntervalPolls(): void
    {
        $server = $this->serverStreaming(
            [['chunks' => [self::eventOfTheFullPayloadWith(self::PAGE_SIZE_OF_25)]]],
            ['/server/polling/v1' => [
                'status' => 200,
                'body' => json_encode(
                    ['kind' => 'full', 'configs' => ['page-size' => self::PAGE_SIZE_OF_50]],
                    JSON_THROW_ON_ERROR,
                ),
            ]],
        );
        $streamingProcess = $this->streamingProcessHoldingTheLocalStore();
        $this->advanceUntil($streamingProcess, fn(): bool => $this->storedPayload() !== null);
        $this->advanceTheClockWhileTheStreamShowsSignsOfLife($streamingProcess, 30_000);
        $streamingProcess->letGoOfTheLocalStore();

        $this->clock->epochMilliseconds += 59_999;
        $this->aRequestReadsThePageSize();
        $pollsOneMillisecondBeforeThePollingInterval = $server->requestsReceivedAt('/server/polling/v1');
        $this->clock->epochMilliseconds += 1;
        $this->aRequestReadsThePageSize();
        $pageSizeReadByTheNextRequest = $this->aRequestReadsThePageSize();

        self::assertSame([], $pollsOneMillisecondBeforeThePollingInterval);
        self::assertCount(1, $server->requestsReceivedAt('/server/polling/v1'));
        self::assertSame(50, $pageSizeReadByTheNextRequest);
    }

    public function testAfterItStopsARequestSendsTheTelemetryAgain(): void
    {
        $server = $this->serverStreaming([['chunks' => [self::eventOfTheFullPayloadWith(self::PAGE_SIZE_OF_25)]]]);
        $streamingProcess = $this->streamingProcessHoldingTheLocalStore();
        $this->advanceUntil($streamingProcess, fn(): bool => $this->storedPayload() !== null);
        $this->aRequestReadsThePageSize();
        $this->clock->epochMilliseconds += 10_000;
        $this->aRequestReadsThePageSize();
        $reportsSentWhileItRan = $server->requestsReceivedAt('/server/telemetry/v1');

        $streamingProcess->letGoOfTheLocalStore();
        $this->aRequestReadsThePageSize();

        $reports = $server->requestsReceivedAt('/server/telemetry/v1');
        self::assertSame([], $reportsSentWhileItRan);
        self::assertCount(1, $reports);
        self::assertIsArray($reports[0]['body']);
        self::assertIsArray($reports[0]['body']['metaContext']);
        self::assertArrayNotHasKey('appName', $reports[0]['body']['metaContext']);
    }

    public function testAfterATelemetryReportRefusedWithA401TheStreamingProcessLogsOneWarningSendsNoNextReportAndKeepsItsStream(): void
    {
        $server = $this->serverStreaming(
            [['chunks' => [self::eventOfTheFullPayloadWith(self::PAGE_SIZE_OF_25)]]],
            ['/server/telemetry/v1' => ['status' => 401]],
        );
        $streamingProcess = $this->streamingProcessHoldingTheLocalStore();
        $this->advanceUntil($streamingProcess, fn(): bool => $this->storedPayload() !== null);
        $this->aRequestReadsThePageSize();
        $this->advanceTheClockWhileTheStreamShowsSignsOfLife($streamingProcess, 30_000);
        $reportsBeforeThePause = count($server->requestsReceivedAt('/server/telemetry/v1'));

        $this->advanceTheClockWhileTheStreamShowsSignsOfLife($streamingProcess, 30_000);
        $this->aRequestReadsThePageSize();
        $this->advanceTheClockWhileTheStreamShowsSignsOfLife($streamingProcess, 30_000);

        self::assertSame(1, $reportsBeforeThePause);
        self::assertCount(1, $server->requestsReceivedAt('/server/telemetry/v1'));
        self::assertSame([['status' => 401]], $this->logger->contextsLoggedAt(LogLevel::WARNING));
        self::assertCount(1, $server->requestsReceivedAt('/server/sse/v1'));
    }

    public function testWhileItsReportsAreStoppedTheStreamingProcessLeavesTheTelemetryOfAnotherUrlInTheLocalStore(): void
    {
        $server = $this->serverStreaming(
            [['chunks' => [self::eventOfTheFullPayloadWith(self::PAGE_SIZE_OF_25)]]],
            ['/server/telemetry/v1' => ['status' => 401]],
        );
        $streamingProcess = $this->streamingProcessHoldingTheLocalStore();
        $this->advanceUntil($streamingProcess, fn(): bool => $this->storedPayload() !== null);
        $this->aRequestReadsThePageSize();
        $this->advanceTheClockWhileTheStreamShowsSignsOfLife($streamingProcess, 30_000);

        $this->aRequestReadsThePageSize('http://127.0.0.1:9');
        $this->advanceTheClockWhileTheStreamShowsSignsOfLife($streamingProcess, 30_000);

        self::assertCount(1, $server->requestsReceivedAt('/server/telemetry/v1'));
        self::assertFileExists($this->localStoreFolder() . DIRECTORY_SEPARATOR . 'telemetry.jsonl');
    }

    public function testTheStreamingProcessSendsTheTelemetryWhileTheStreamIsDown(): void
    {
        $server = $this->serverStreaming([['status' => 500]]);
        $streamingProcess = $this->streamingProcessHoldingTheLocalStore();
        $this->advanceUntil($streamingProcess, fn(): bool => count($this->reconnectionsLogged()) === 1);
        $refreshLock = $this->localStore()->refreshLockAcquiredWithin(0);
        $refreshLock?->replaceStoredPayload(new StoredPayload(
            ['kind' => 'full', 'configs' => ['page-size' => self::PAGE_SIZE_OF_25]],
            $this->clock->epochMilliseconds,
            'session-of-the-polls',
        ));
        $refreshLock?->release();
        $this->aRequestReadsThePageSize();
        $this->randomFraction = 0.999;

        $this->clock->epochMilliseconds += 29_999;
        $this->advanceUntil($streamingProcess, fn(): bool => count($this->reconnectionsLogged()) === 2);
        $reportsOneMillisecondBeforeTheFlushInterval = $server->requestsReceivedAt('/server/telemetry/v1');
        $this->clock->epochMilliseconds += 1;
        $streamingProcess->advance();

        self::assertSame([], $reportsOneMillisecondBeforeTheFlushInterval);
        self::assertCount(1, $server->requestsReceivedAt('/server/telemetry/v1'));
    }

    /**
     * @param list<ScriptedStream> $streamsInOrder
     * @param array<string, array{status?: int, body?: string}> $answersByPath
     */
    private function serverStreaming(array $streamsInOrder, array $answersByPath = []): ScriptedStreamServer
    {
        return $this->server = ScriptedStreamServer::streaming($streamsInOrder, $answersByPath);
    }

    private function streamingProcessHoldingTheLocalStore(): DefaultStreamingProcess
    {
        $this->streamingProcess = StreamingProcessFactory::streamingProcessTimedBy(
            $this->clock,
            fn(): float => $this->randomFraction,
            'server-sdk-key',
            new Metadata(appName: 'stream-host'),
            new ConnectionOptions(url: $this->server->url ?? '', storagePath: $this->storagePath),
            new TelemetryOptions(flushInterval: 30_000),
            $this->logger,
        );
        $this->streamingProcess->takeHoldOfTheLocalStore();

        return $this->streamingProcess;
    }

    /**
     * @param \Closure(): bool $isReached
     */
    private function advanceUntil(DefaultStreamingProcess $streamingProcess, \Closure $isReached): void
    {
        $deadline = hrtime(true) + 5_000_000_000;
        for (;;) {
            $streamingProcess->advance();
            if ($isReached()) {
                return;
            }
            if (hrtime(true) > $deadline) {
                self::fail('What the test waits for did not happen within five seconds.');
            }
            usleep(1_000);
        }
    }

    private function advanceForRealMilliseconds(DefaultStreamingProcess $streamingProcess, int $milliseconds): void
    {
        $deadline = hrtime(true) + $milliseconds * 1_000_000;
        while (hrtime(true) < $deadline) {
            $streamingProcess->advance();
            usleep(1_000);
        }
    }

    private function advanceTheClockWhileTheStreamShowsSignsOfLife(
        DefaultStreamingProcess $streamingProcess,
        int $milliseconds,
    ): void {
        $millisecondsBetweenSignsOfLife = 40_000;
        for ($remaining = $milliseconds; $remaining > 0; $remaining -= $millisecondsBetweenSignsOfLife) {
            $this->clock->epochMilliseconds += min($remaining, $millisecondsBetweenSignsOfLife);
            $errorsLoggedBefore = count($this->logger->contextsLoggedAt(LogLevel::ERROR));
            $this->server?->sendToTheOpenStream("data: a sign of life that carries no payload\n\n");
            $this->advanceUntil(
                $streamingProcess,
                fn(): bool => count($this->logger->contextsLoggedAt(LogLevel::ERROR)) === $errorsLoggedBefore + 1,
            );
        }
    }

    private function aRequestReadsThePageSize(?string $serverUrl = null): int
    {
        $serverUrl ??= $this->server->url ?? '';
        $requestLogger = new NullLogger();
        $localStore = new LocalStoreOpenedOnFirstUse('server-sdk-key', $this->storagePath, $requestLogger);
        $clientLifetime = new ClientLifetime($this->clock, 10_000);
        $sdkIdentity = SdkIdentity::ofTheServerSdk();
        $client = new DefaultConfigDirectorClient(
            new LocalStoreConnection(
                fn(\Closure $reportPayloadReceivedByAPoll): PollingTransport => new PollingTransport(
                    $localStore->store(),
                    new PollingEndpoint($serverUrl, 'server-sdk-key', new Metadata(), $sdkIdentity),
                    PollingInterval::ofConfiguredSeconds(60),
                    3_000,
                    $clientLifetime,
                    $this->clock,
                    $requestLogger,
                    static function (): void {},
                    $reportPayloadReceivedByAPoll,
                ),
                $requestLogger,
            ),
            new Metadata(),
            3_000,
            $requestLogger,
            new EventHandlers($requestLogger),
            new LocalStoreTelemetry(
                $localStore,
                new EventReporter(
                    new TelemetryEndpoint($serverUrl, 'server-sdk-key', new Metadata(), $sdkIdentity),
                    5_000,
                    $this->clock,
                    $requestLogger,
                ),
                5_000,
                10_000,
                $clientLifetime,
                $this->clock,
                $requestLogger,
            ),
        );
        $client->initialize();
        $pageSize = $client->getValue('page-size', 10, new Context(id: 'user-42'));
        $client->dispose();

        return $pageSize;
    }

    /**
     * @param array{body: mixed} $report
     *
     * @return list<mixed>
     */
    private static function evaluationCountsReportedIn(array $report): array
    {
        $aggregatedEvents = is_array($report['body']) ? $report['body']['aggregatedEvents'] ?? null : null;
        $evaluatedConfig = is_array($aggregatedEvents) ? $aggregatedEvents['evaluatedConfig'] ?? null : null;

        return is_array($evaluatedConfig) ? array_column($evaluatedConfig, 'count') : [];
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private function reconnectionsLogged(): array
    {
        return [...$this->reconnectionsLoggedAt(LogLevel::INFO), ...$this->reconnectionsLoggedAt(LogLevel::WARNING)];
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private function reconnectionsLoggedAt(string $level): array
    {
        return array_values(array_filter(
            $this->logger->contextsLoggedAt($level),
            static fn(array $context): bool => array_key_exists('attempt', $context),
        ));
    }

    private function localStore(): LocalStore
    {
        return LocalStore::ofServerSdkKey('server-sdk-key', $this->storagePath, new NullLogger());
    }

    private function localStoreFolder(): string
    {
        return $this->storagePath . DIRECTORY_SEPARATOR . hash('sha256', 'server-sdk-key');
    }

    private function storedPayload(): ?StoredPayload
    {
        return $this->localStore()->storedPayload();
    }

    /**
     * @return list<array-key>
     */
    private function storedConfigKeys(): array
    {
        $configs = $this->storedPayload()->payload['configs'] ?? null;

        return is_array($configs) ? array_keys($configs) : [];
    }

    private function storedPageSize(): ?string
    {
        $configs = $this->storedPayload()->payload['configs'] ?? null;
        $pageSize = is_array($configs) ? $configs['page-size'] ?? null : null;
        $target = is_array($pageSize) ? $pageSize['target'] ?? null : null;
        $servedValue = is_array($target) ? $target['defaultValue'] ?? null : null;

        return is_string($servedValue) ? $servedValue : null;
    }

    private static function eventOfTheFullPayloadWithThePageSizeAndTheBanner(): string
    {
        return ScriptedStreamServer::eventCarrying([
            'kind' => 'full',
            'configs' => ['page-size' => self::PAGE_SIZE_OF_25, 'banner' => self::BANNER],
        ]);
    }

    /**
     * @param array<string, mixed> $pageSizeConfig
     */
    private static function eventOfTheFullPayloadWith(array $pageSizeConfig): string
    {
        return ScriptedStreamServer::eventCarrying(['kind' => 'full', 'configs' => ['page-size' => $pageSizeConfig]]);
    }
}
