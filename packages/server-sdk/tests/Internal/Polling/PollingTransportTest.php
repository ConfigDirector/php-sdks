<?php

declare(strict_types=1);

namespace ConfigDirector\Tests\Internal\Polling;

use ConfigDirector\ConfigDirectorConnectionException;
use ConfigDirector\Internal\LocalStore\LocalStore;
use ConfigDirector\Internal\LocalStore\PollingPause;
use ConfigDirector\Internal\LocalStore\StoredPayload;
use ConfigDirector\Internal\Polling\PollingEndpoint;
use ConfigDirector\Internal\Polling\PollingInterval;
use ConfigDirector\Internal\Polling\PollingTransport;
use ConfigDirector\Metadata;
use ConfigDirector\Tests\Support\PhpProcess;
use ConfigDirector\Tests\Support\RecordingLogger;
use ConfigDirector\Tests\Support\ScriptedSdkServer;
use ConfigDirector\Tests\Support\SettableClock;
use ConfigDirector\Tests\Support\TemporaryFolder;
use ConfigDirector\Version;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

/**
 * @phpstan-import-type ScriptedAnswer from ScriptedSdkServer
 */
final class PollingTransportTest extends TestCase
{
    private const int NOVEMBER_12_2026_AT_NOON = 1_794_484_800_000;

    private string $storagePath;

    private RecordingLogger $logger;

    private SettableClock $clock;

    /** @var list<ConfigDirectorConnectionException> */
    private array $unrecoverableErrorsReported = [];

    /** @var list<bool> */
    private array $refreshLockWasFreeWhileReporting = [];

    /** @var list<ScriptedSdkServer> */
    private array $servers = [];

    #[\Override]
    protected function setUp(): void
    {
        $this->storagePath = TemporaryFolder::create();
        $this->logger = new RecordingLogger();
        $this->clock = new SettableClock(self::NOVEMBER_12_2026_AT_NOON);
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->servers as $server) {
            $server->stop();
        }
        TemporaryFolder::remove($this->storagePath);
    }

    public function testInitializationOnAnEmptyStoreSendsOnePollAndReturnsThePayloadItReceived(): void
    {
        $server = $this->serverAnswering([
            ['status' => 200, 'body' => ['kind' => 'full', 'configs' => ['flag' => ['key' => 'flag']]]],
        ]);
        $transport = $this->transportPolling($server);

        $storedPayload = $transport->storedPayloadForInitialization();

        self::assertCount(1, $server->requestsReceived());
        self::assertSame(
            ['kind' => 'full', 'configs' => ['flag' => ['key' => 'flag']]],
            $storedPayload?->payload,
        );
    }

    public function testThePayloadReceivedByInitializationIsStoredAsFreshAtTheTimeOfThePoll(): void
    {
        $server = $this->serverAnswering([
            ['status' => 200, 'body' => ['kind' => 'full', 'configs' => ['flag' => ['key' => 'flag']]]],
        ]);
        $transport = $this->transportPolling($server);

        $transport->storedPayloadForInitialization();

        $storedPayload = $this->storeOpenedAgain()->storedPayload();
        self::assertSame(['kind' => 'full', 'configs' => ['flag' => ['key' => 'flag']]], $storedPayload?->payload);
        self::assertSame(self::NOVEMBER_12_2026_AT_NOON, $storedPayload->confirmedFreshAtEpochMilliseconds);
    }

    public function testAPollIsAPostOfJsonToThePollingPathWithTheUserAgentOfTheSdk(): void
    {
        $server = $this->serverAnswering([['status' => 200, 'body' => ['kind' => 'full', 'configs' => []]]]);
        $transport = $this->transportPolling($server);

        $transport->storedPayloadForInitialization();

        $request = $server->requestsReceived()[0];
        self::assertSame('POST', $request['method']);
        self::assertSame('/server/polling/v1', $request['path']);
        self::assertSame('application/json', $request['headers']['content-type']);
        self::assertSame('php-server-sdk/' . Version::CURRENT, $request['headers']['user-agent']);
    }

    public function testAPollCarriesTheServerSdkKeyTheSdkNameAndVersionAndTheApplicationMetadata(): void
    {
        $server = $this->serverAnswering([['status' => 200, 'body' => ['kind' => 'full', 'configs' => []]]]);
        $transport = $this->transportPolling($server, metadata: new Metadata('checkout-api', '4.2.0'));

        $transport->storedPayloadForInitialization();

        $body = $server->requestsReceived()[0]['body'];
        self::assertIsArray($body);
        self::assertSame('server-sdk-key', $body['serverSdkKey']);
        self::assertSame(
            [
                'sdkName' => 'php-server-sdk',
                'sdkVersion' => Version::CURRENT,
                'appName' => 'checkout-api',
                'appVersion' => '4.2.0',
            ],
            $body['metaContext'],
        );
    }

    public function testAPollFromAnApplicationWithoutMetadataCarriesOnlyTheSdkNameAndVersion(): void
    {
        $server = $this->serverAnswering([['status' => 200, 'body' => ['kind' => 'full', 'configs' => []]]]);
        $transport = $this->transportPolling($server);

        $transport->storedPayloadForInitialization();

        $body = $server->requestsReceived()[0]['body'];
        self::assertIsArray($body);
        self::assertSame(['sdkName' => 'php-server-sdk', 'sdkVersion' => Version::CURRENT], $body['metaContext']);
    }

    public function testTheFirstPollOfAHostCarriesANewSessionIdAndNoLastUpdateTimestamp(): void
    {
        $server = $this->serverAnswering([['status' => 200, 'body' => ['kind' => 'full', 'configs' => []]]]);
        $transport = $this->transportPolling($server);

        $transport->storedPayloadForInitialization();

        $body = $server->requestsReceived()[0]['body'];
        self::assertIsArray($body);
        self::assertIsString($body['sessionId']);
        self::assertMatchesRegularExpression(
            '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/',
            $body['sessionId'],
        );
        self::assertArrayNotHasKey('lastUpdateTimestamp', $body);
        self::assertSame($body['sessionId'], $this->storeOpenedAgain()->storedPayload()?->sessionId);
    }

    public function testARequestThatFindsTheStoreYoungerThanThePollingIntervalSendsNoPollWhenItCloses(): void
    {
        $server = $this->serverAnswering([['status' => 200, 'body' => ['kind' => 'full', 'configs' => []]]]);
        $this->storePayload(['kind' => 'full', 'configs' => []], self::NOVEMBER_12_2026_AT_NOON);
        $this->clock->epochMilliseconds = self::NOVEMBER_12_2026_AT_NOON + 59_999;
        $transport = $this->transportPolling($server);
        $transport->storedPayloadForInitialization();

        $transport->close();

        self::assertSame([], $server->requestsReceived());
    }

    public function testARequestThatFindsTheStoreOlderThanThePollingIntervalIsGivenTheStoredPayloadWithoutPolling(): void
    {
        $server = $this->serverAnswering([
            ['status' => 200, 'body' => ['kind' => 'full', 'configs' => ['flag' => ['value' => 'new']]]],
        ]);
        $this->storePayload(
            ['kind' => 'full', 'configs' => ['flag' => ['value' => 'old']]],
            self::NOVEMBER_12_2026_AT_NOON,
        );
        $this->clock->epochMilliseconds = self::NOVEMBER_12_2026_AT_NOON + 60_000;
        $transport = $this->transportPolling($server);

        $storedPayload = $transport->storedPayloadForInitialization();

        self::assertSame(['kind' => 'full', 'configs' => ['flag' => ['value' => 'old']]], $storedPayload?->payload);
        self::assertSame([], $server->requestsReceived());
    }

    public function testARequestThatFindsTheStoreOlderThanThePollingIntervalPollsWhenItClosesAndStoresThePayload(): void
    {
        $server = $this->serverAnswering([
            ['status' => 200, 'body' => ['kind' => 'full', 'configs' => ['flag' => ['value' => 'new']]]],
        ]);
        $this->storePayload(
            ['kind' => 'full', 'configs' => ['flag' => ['value' => 'old']]],
            self::NOVEMBER_12_2026_AT_NOON,
        );
        $this->clock->epochMilliseconds = self::NOVEMBER_12_2026_AT_NOON + 60_000;
        $transport = $this->transportPolling($server);
        $transport->storedPayloadForInitialization();

        $transport->close();

        self::assertCount(1, $server->requestsReceived());
        self::assertEquals(
            new StoredPayload(
                ['kind' => 'full', 'configs' => ['flag' => ['value' => 'new']]],
                self::NOVEMBER_12_2026_AT_NOON + 60_000,
                'session-1',
            ),
            $this->storeOpenedAgain()->storedPayload(),
        );
    }

    public function testAPollOfAStaleStoreCarriesTheStoredSessionIdAndTheTimestampOfTheStoredPayload(): void
    {
        $server = $this->serverAnswering([['status' => 204]]);
        $this->storePayload(
            ['kind' => 'full', 'timestamp' => '2026-11-12T11:58:30.123456Z', 'configs' => []],
            self::NOVEMBER_12_2026_AT_NOON,
        );
        $this->clock->epochMilliseconds = self::NOVEMBER_12_2026_AT_NOON + 60_000;
        $transport = $this->transportPolling($server);
        $transport->storedPayloadForInitialization();

        $transport->close();

        $body = $server->requestsReceived()[0]['body'];
        self::assertIsArray($body);
        self::assertSame('session-1', $body['sessionId']);
        self::assertSame('2026-11-12T11:58:30.123456Z', $body['lastUpdateTimestamp']);
    }

    public function testAStoredPayloadWithoutATimestampIsPolledWithoutALastUpdateTimestamp(): void
    {
        $server = $this->serverAnswering([['status' => 200, 'body' => ['kind' => 'full', 'configs' => []]]]);
        $this->storePayload(['kind' => 'full', 'configs' => []], self::NOVEMBER_12_2026_AT_NOON);
        $this->clock->epochMilliseconds = self::NOVEMBER_12_2026_AT_NOON + 60_000;
        $transport = $this->transportPolling($server);
        $transport->storedPayloadForInitialization();

        $transport->close();

        $body = $server->requestsReceived()[0]['body'];
        self::assertIsArray($body);
        self::assertArrayNotHasKey('lastUpdateTimestamp', $body);
    }

    public function testA204LeavesTheStoredValuesAndMarksThemFresh(): void
    {
        $server = $this->serverAnswering([['status' => 204]]);
        $this->storePayload(
            ['kind' => 'full', 'timestamp' => '2026-11-12T11:58:30Z', 'configs' => ['flag' => ['value' => 'kept']]],
            self::NOVEMBER_12_2026_AT_NOON,
        );
        $this->clock->epochMilliseconds = self::NOVEMBER_12_2026_AT_NOON + 75_000;
        $transport = $this->transportPolling($server);
        $transport->storedPayloadForInitialization();

        $transport->close();

        self::assertEquals(
            new StoredPayload(
                [
                    'kind' => 'full',
                    'timestamp' => '2026-11-12T11:58:30Z',
                    'configs' => ['flag' => ['value' => 'kept']],
                ],
                self::NOVEMBER_12_2026_AT_NOON + 75_000,
                'session-1',
            ),
            $this->storeOpenedAgain()->storedPayload(),
        );
    }

    public function testAfterA204TheNextRequestInsideTheIntervalSendsNoPoll(): void
    {
        $server = $this->serverAnswering([['status' => 204]]);
        $this->storePayload(['kind' => 'full', 'configs' => []], self::NOVEMBER_12_2026_AT_NOON);
        $this->clock->epochMilliseconds = self::NOVEMBER_12_2026_AT_NOON + 75_000;
        $requestThatPolls = $this->transportPolling($server);
        $requestThatPolls->storedPayloadForInitialization();
        $requestThatPolls->close();

        $this->clock->epochMilliseconds = self::NOVEMBER_12_2026_AT_NOON + 134_999;
        $nextRequest = $this->transportPolling($server);
        $nextRequest->storedPayloadForInitialization();
        $nextRequest->close();

        self::assertCount(1, $server->requestsReceived());
    }

    public function testADeltaIsMergedIntoTheStoredPayloadUnderItsOwnTimestamp(): void
    {
        $server = $this->serverAnswering([[
            'status' => 200,
            'body' => [
                'payloadVersion' => 1,
                'kind' => 'delta',
                'timestamp' => '2026-11-12T12:01:00Z',
                'configs' => ['changed' => ['value' => 'new'], 'added' => ['value' => 'new']],
                'segments' => ['segment-2' => ['name' => 'added']],
            ],
        ]]);
        $this->storePayload(
            [
                'payloadVersion' => 1,
                'kind' => 'full',
                'timestamp' => '2026-11-12T11:58:30Z',
                'configs' => ['untouched' => ['value' => 'old'], 'changed' => ['value' => 'old']],
                'segments' => ['segment-1' => ['name' => 'kept']],
            ],
            self::NOVEMBER_12_2026_AT_NOON,
        );
        $this->clock->epochMilliseconds = self::NOVEMBER_12_2026_AT_NOON + 60_000;
        $transport = $this->transportPolling($server);
        $transport->storedPayloadForInitialization();

        $transport->close();

        self::assertEquals(
            [
                'payloadVersion' => 1,
                'kind' => 'full',
                'timestamp' => '2026-11-12T12:01:00Z',
                'configs' => [
                    'untouched' => ['value' => 'old'],
                    'changed' => ['value' => 'new'],
                    'added' => ['value' => 'new'],
                ],
                'segments' => ['segment-1' => ['name' => 'kept'], 'segment-2' => ['name' => 'added']],
            ],
            $this->storeOpenedAgain()->storedPayload()?->payload,
        );
    }

    public function testADeltaKeepsAStoredConfigWhoseKeyIsANumber(): void
    {
        $server = $this->serverAnswering([[
            'status' => 200,
            'body' => ['kind' => 'delta', 'configs' => ['2027' => ['value' => 'new']], 'segments' => []],
        ]]);
        $this->storePayload(
            ['kind' => 'full', 'configs' => ['2026' => ['value' => 'old']], 'segments' => []],
            self::NOVEMBER_12_2026_AT_NOON,
        );
        $this->clock->epochMilliseconds = self::NOVEMBER_12_2026_AT_NOON + 60_000;
        $transport = $this->transportPolling($server);
        $transport->storedPayloadForInitialization();

        $transport->close();

        self::assertSame(
            ['2026' => ['value' => 'old'], '2027' => ['value' => 'new']],
            $this->storeOpenedAgain()->storedPayload()?->payload['configs'],
        );
    }

    public function testAFullPayloadReplacesTheStoredPayloadSoAConfigItLeavesOutIsGone(): void
    {
        $server = $this->serverAnswering([[
            'status' => 200,
            'body' => ['kind' => 'full', 'configs' => ['kept' => ['value' => 'new']]],
        ]]);
        $this->storePayload(
            ['kind' => 'full', 'configs' => ['kept' => ['value' => 'old'], 'deleted' => ['value' => 'old']]],
            self::NOVEMBER_12_2026_AT_NOON,
        );
        $this->clock->epochMilliseconds = self::NOVEMBER_12_2026_AT_NOON + 60_000;
        $transport = $this->transportPolling($server);
        $transport->storedPayloadForInitialization();

        $transport->close();

        self::assertSame(
            ['kind' => 'full', 'configs' => ['kept' => ['value' => 'new']]],
            $this->storeOpenedAgain()->storedPayload()?->payload,
        );
    }

    public function testAConfiguredIntervalOfThirtySecondsSendsNoPollBeforeSixtySecondsHavePassed(): void
    {
        $server = $this->serverAnswering([['status' => 204]]);
        $this->storePayload(['kind' => 'full', 'configs' => []], self::NOVEMBER_12_2026_AT_NOON);
        $this->clock->epochMilliseconds = self::NOVEMBER_12_2026_AT_NOON + 59_999;
        $transport = $this->transportPolling($server, configuredIntervalSeconds: 30);
        $transport->storedPayloadForInitialization();

        $transport->close();

        self::assertSame([], $server->requestsReceived());
    }

    public function testAConfiguredIntervalOfThirtySecondsPollsOnceSixtySecondsHavePassed(): void
    {
        $server = $this->serverAnswering([['status' => 204]]);
        $this->storePayload(['kind' => 'full', 'configs' => []], self::NOVEMBER_12_2026_AT_NOON);
        $this->clock->epochMilliseconds = self::NOVEMBER_12_2026_AT_NOON + 60_000;
        $transport = $this->transportPolling($server, configuredIntervalSeconds: 30);
        $transport->storedPayloadForInitialization();

        $transport->close();

        self::assertCount(1, $server->requestsReceived());
    }

    public function testAConfiguredIntervalOfFiveMinutesSendsNoPollBeforeFiveMinutesHavePassed(): void
    {
        $server = $this->serverAnswering([['status' => 204]]);
        $this->storePayload(['kind' => 'full', 'configs' => []], self::NOVEMBER_12_2026_AT_NOON);
        $this->clock->epochMilliseconds = self::NOVEMBER_12_2026_AT_NOON + 299_999;
        $transport = $this->transportPolling($server, configuredIntervalSeconds: 300);
        $transport->storedPayloadForInitialization();

        $transport->close();

        self::assertSame([], $server->requestsReceived());
    }

    public function testClosingTwiceSendsOnePoll(): void
    {
        $server = $this->serverAnswering([['status' => 204], ['status' => 204]]);
        $this->storePayload(['kind' => 'full', 'configs' => []], self::NOVEMBER_12_2026_AT_NOON);
        $this->clock->epochMilliseconds = self::NOVEMBER_12_2026_AT_NOON + 60_000;
        $transport = $this->transportPolling($server);
        $transport->storedPayloadForInitialization();
        $transport->close();

        $this->clock->epochMilliseconds = self::NOVEMBER_12_2026_AT_NOON + 120_000;
        $transport->close();

        self::assertCount(1, $server->requestsReceived());
    }

    public function testATransportThatWasNeverInitializedSendsNoPollWhenItCloses(): void
    {
        $server = $this->serverAnswering([['status' => 200, 'body' => ['kind' => 'full', 'configs' => []]]]);
        $transport = $this->transportPolling($server);

        $transport->close();

        self::assertSame([], $server->requestsReceived());
    }

    public function testARequestThatClosesWhileAnotherProcessHoldsTheRefreshLockSendsNoPoll(): void
    {
        $server = $this->serverAnswering([['status' => 204]]);
        $this->storePayload(['kind' => 'full', 'configs' => []], self::NOVEMBER_12_2026_AT_NOON);
        $this->clock->epochMilliseconds = self::NOVEMBER_12_2026_AT_NOON + 60_000;
        $transport = $this->transportPolling($server);
        $transport->storedPayloadForInitialization();
        $lockOfAnotherProcess = $this->storeOpenedAgain()->refreshLockAcquiredWithin(0);
        self::assertNotNull($lockOfAnotherProcess);

        $transport->close();
        $lockOfAnotherProcess->release();

        self::assertSame([], $server->requestsReceived());
    }

    public function testARequestThatFoundTheStoreStaleSendsNoPollWhenAnotherProcessRefreshedItMeanwhile(): void
    {
        $server = $this->serverAnswering([['status' => 204]]);
        $this->storePayload(['kind' => 'full', 'configs' => []], self::NOVEMBER_12_2026_AT_NOON);
        $this->clock->epochMilliseconds = self::NOVEMBER_12_2026_AT_NOON + 60_000;
        $transport = $this->transportPolling($server);
        $transport->storedPayloadForInitialization();
        $this->storePayload(['kind' => 'full', 'configs' => []], self::NOVEMBER_12_2026_AT_NOON + 59_000);

        $transport->close();

        self::assertSame([], $server->requestsReceived());
    }

    public function testAPollWhoseConnectionIsClosedWithoutAnAnswerIsSentAgainAtOnceAndYieldsThePayload(): void
    {
        $server = $this->serverAnswering([
            ['closes' => 'before-answering'],
            ['status' => 200, 'body' => ['kind' => 'full', 'configs' => ['flag' => ['value' => 'served']]]],
        ]);
        $transport = $this->transportPolling($server);

        $storedPayload = $transport->storedPayloadForInitialization();

        self::assertSame(['kind' => 'full', 'configs' => ['flag' => ['value' => 'served']]], $storedPayload?->payload);
        self::assertCount(2, $server->requestsReceived());
    }

    public function testAPollThatIsAnsweredOnItsSecondAttemptLogsNoWarningAndNoError(): void
    {
        $server = $this->serverAnswering([
            ['closes' => 'before-answering'],
            ['status' => 200, 'body' => ['kind' => 'full', 'configs' => []]],
        ]);
        $transport = $this->transportPolling($server);

        $transport->storedPayloadForInitialization();

        self::assertSame([], $this->logger->contextsLoggedAt(LogLevel::ERROR));
        self::assertSame([], $this->logger->contextsLoggedAt(LogLevel::WARNING));
    }

    public function testThePollSentAgainCarriesTheSessionIdOfTheFirstAttempt(): void
    {
        $server = $this->serverAnswering([
            ['closes' => 'before-answering'],
            ['status' => 200, 'body' => ['kind' => 'full', 'configs' => []]],
        ]);
        $transport = $this->transportPolling($server);

        $transport->storedPayloadForInitialization();

        [$firstAttempt, $secondAttempt] = $server->requestsReceived();
        self::assertIsArray($firstAttempt['body']);
        self::assertIsArray($secondAttempt['body']);
        self::assertSame($firstAttempt['body']['sessionId'], $secondAttempt['body']['sessionId']);
    }

    public function testAPollWhoseAnswerIsCutOffMidBodyIsSentAgainAtOnce(): void
    {
        $server = $this->serverAnswering([
            ['status' => 200, 'body' => '{"kind":"full","conf', 'closes' => 'mid-body'],
            ['status' => 200, 'body' => ['kind' => 'full', 'configs' => ['flag' => ['value' => 'served']]]],
        ]);
        $transport = $this->transportPolling($server);

        $storedPayload = $transport->storedPayloadForInitialization();

        self::assertSame(['kind' => 'full', 'configs' => ['flag' => ['value' => 'served']]], $storedPayload?->payload);
        self::assertCount(2, $server->requestsReceived());
    }

    public function testAPollWhoseConnectionIsClosedTwiceIsSentTwiceAndLogsOneError(): void
    {
        $server = $this->serverAnswering([
            ['closes' => 'before-answering'],
            ['closes' => 'before-answering'],
            ['status' => 200, 'body' => ['kind' => 'full', 'configs' => []]],
        ]);
        $transport = $this->transportPolling($server);

        $storedPayload = $transport->storedPayloadForInitialization();

        self::assertNull($storedPayload);
        self::assertCount(2, $server->requestsReceived());
        self::assertCount(1, $this->logger->contextsLoggedAt(LogLevel::ERROR));
        self::assertNull($this->statusOfTheOneErrorLogged());
    }

    public function testA503IsNotSentAgainAndLogsOneErrorWithItsStatus(): void
    {
        $server = $this->serverAnswering([
            ['status' => 503, 'body' => 'Service Unavailable'],
            ['status' => 200, 'body' => ['kind' => 'full', 'configs' => []]],
        ]);
        $transport = $this->transportPolling($server);

        $storedPayload = $transport->storedPayloadForInitialization();

        self::assertNull($storedPayload);
        self::assertCount(1, $server->requestsReceived());
        self::assertSame(503, $this->statusOfTheOneErrorLogged());
    }

    public function testARedirectIsNotFollowedAndLogsOneErrorWithItsStatus(): void
    {
        $server = $this->serverAnswering([
            ['status' => 302, 'headers' => ['Location' => '/server/polling/v1']],
            ['status' => 200, 'body' => ['kind' => 'full', 'configs' => []]],
        ]);
        $transport = $this->transportPolling($server);

        $storedPayload = $transport->storedPayloadForInitialization();

        self::assertNull($storedPayload);
        self::assertCount(1, $server->requestsReceived());
        self::assertSame(302, $this->statusOfTheOneErrorLogged());
    }

    public function testAPollThatRunsPastItsTimeLimitIsNotSentAgainAndLogsOneError(): void
    {
        $server = $this->serverAnswering([
            ['status' => 200, 'body' => ['kind' => 'full', 'configs' => []], 'delayMilliseconds' => 1_500],
            ['status' => 200, 'body' => ['kind' => 'full', 'configs' => []]],
        ]);
        $transport = $this->transportPolling($server, timeoutMilliseconds: 300);

        $storedPayload = $transport->storedPayloadForInitialization();

        self::assertNull($storedPayload);
        self::assertCount(1, $server->requestsReceived());
        self::assertNull($this->statusOfTheOneErrorLogged());
    }

    public function testAnAnswerThatIsNotJsonIsNotSentAgainAndLogsOneError(): void
    {
        $server = $this->serverAnswering([
            ['status' => 200, 'body' => '<html>proxy error</html>'],
            ['status' => 200, 'body' => ['kind' => 'full', 'configs' => []]],
        ]);
        $transport = $this->transportPolling($server);

        $storedPayload = $transport->storedPayloadForInitialization();

        self::assertNull($storedPayload);
        self::assertCount(1, $server->requestsReceived());
        self::assertCount(1, $this->logger->contextsLoggedAt(LogLevel::ERROR));
    }

    public function testAnAnswerThatIsAJsonListIsNotStoredAndLogsOneError(): void
    {
        $server = $this->serverAnswering([['status' => 200, 'body' => '["kind","full"]']]);
        $transport = $this->transportPolling($server);

        $storedPayload = $transport->storedPayloadForInitialization();

        self::assertNull($storedPayload);
        self::assertNull($this->storeOpenedAgain()->storedPayload());
        self::assertCount(1, $this->logger->contextsLoggedAt(LogLevel::ERROR));
    }

    public function testAnAnswerLargerThan32MebibytesIsNotStoredAndLogsOneError(): void
    {
        $server = $this->serverAnswering([
            ['status' => 200, 'body' => '{"kind":"full","configs":{}}', 'bodyBytes' => 32 * 1024 * 1024 + 1],
        ]);
        $transport = $this->transportPolling($server, timeoutMilliseconds: 30_000);

        $storedPayload = $transport->storedPayloadForInitialization();

        self::assertNull($storedPayload);
        self::assertNull($this->storeOpenedAgain()->storedPayload());
        self::assertCount(1, $this->logger->contextsLoggedAt(LogLevel::ERROR));
    }

    public function testAnAnswerOfExactly32MebibytesIsRead(): void
    {
        $server = $this->serverAnswering([
            ['status' => 200, 'body' => '{"kind":"full","configs":{}}', 'bodyBytes' => 32 * 1024 * 1024],
        ]);
        $transport = $this->transportPolling($server, timeoutMilliseconds: 30_000);

        $storedPayload = $transport->storedPayloadForInitialization();

        self::assertSame(['kind' => 'full', 'configs' => []], $storedPayload?->payload);
    }

    public function testA204ToAPollOfAnEmptyStoreLogsOneErrorAndStoresNothing(): void
    {
        $server = $this->serverAnswering([['status' => 204]]);
        $transport = $this->transportPolling($server);

        $storedPayload = $transport->storedPayloadForInitialization();

        self::assertNull($storedPayload);
        self::assertNull($this->storeOpenedAgain()->storedPayload());
        self::assertCount(1, $this->logger->contextsLoggedAt(LogLevel::ERROR));
    }

    public function testAfterAFailedPollOfAnEmptyStoreTheNextRequestInsideTheIntervalSendsNoPoll(): void
    {
        $server = $this->serverAnswering([
            ['status' => 503],
            ['status' => 200, 'body' => ['kind' => 'full', 'configs' => []]],
        ]);
        $this->transportPolling($server)->storedPayloadForInitialization();

        $this->clock->epochMilliseconds = self::NOVEMBER_12_2026_AT_NOON + 59_999;
        $storedPayload = $this->transportPolling($server)->storedPayloadForInitialization();

        self::assertNull($storedPayload);
        self::assertCount(1, $server->requestsReceived());
    }

    public function testAfterAFailedPollOfAnEmptyStoreTheFirstRequestAfterTheIntervalPollsUnderTheSameSessionId(): void
    {
        $server = $this->serverAnswering([
            ['status' => 503],
            ['status' => 200, 'body' => ['kind' => 'full', 'configs' => ['flag' => ['value' => 'served']]]],
        ]);
        $this->transportPolling($server)->storedPayloadForInitialization();

        $this->clock->epochMilliseconds = self::NOVEMBER_12_2026_AT_NOON + 60_000;
        $storedPayload = $this->transportPolling($server)->storedPayloadForInitialization();

        self::assertSame(['kind' => 'full', 'configs' => ['flag' => ['value' => 'served']]], $storedPayload?->payload);
        [$failedPoll, $nextPoll] = $server->requestsReceived();
        self::assertIsArray($failedPoll['body']);
        self::assertIsArray($nextPoll['body']);
        self::assertSame($failedPoll['body']['sessionId'], $nextPoll['body']['sessionId']);
        self::assertSame($failedPoll['body']['sessionId'], $storedPayload->sessionId);
    }

    public function testAfterAFailedPollOfAStaleStoreTheNextRequestInsideTheIntervalSendsNoPollAndTheValuesStay(): void
    {
        $server = $this->serverAnswering([['status' => 503], ['status' => 204]]);
        $this->storePayload(['kind' => 'full', 'configs' => []], self::NOVEMBER_12_2026_AT_NOON);
        $this->clock->epochMilliseconds = self::NOVEMBER_12_2026_AT_NOON + 60_000;
        $requestThatPolls = $this->transportPolling($server);
        $requestThatPolls->storedPayloadForInitialization();
        $requestThatPolls->close();

        $this->clock->epochMilliseconds = self::NOVEMBER_12_2026_AT_NOON + 119_999;
        $nextRequest = $this->transportPolling($server);
        $storedPayload = $nextRequest->storedPayloadForInitialization();
        $nextRequest->close();

        self::assertCount(1, $server->requestsReceived());
        self::assertEquals(
            new StoredPayload(['kind' => 'full', 'configs' => []], self::NOVEMBER_12_2026_AT_NOON, 'session-1'),
            $storedPayload,
        );
    }

    public function testAfterAFailedPollOfAStaleStoreTheFirstRequestAfterTheIntervalPollsAgain(): void
    {
        $server = $this->serverAnswering([['status' => 503], ['status' => 204]]);
        $this->storePayload(['kind' => 'full', 'configs' => []], self::NOVEMBER_12_2026_AT_NOON);
        $this->clock->epochMilliseconds = self::NOVEMBER_12_2026_AT_NOON + 60_000;
        $requestThatPolls = $this->transportPolling($server);
        $requestThatPolls->storedPayloadForInitialization();
        $requestThatPolls->close();

        $this->clock->epochMilliseconds = self::NOVEMBER_12_2026_AT_NOON + 120_000;
        $nextRequest = $this->transportPolling($server);
        $nextRequest->storedPayloadForInitialization();
        $nextRequest->close();

        self::assertCount(2, $server->requestsReceived());
    }

    public function testAPayloadTheStoreCannotHoldIsNotGivenToTheRequestThatPolled(): void
    {
        $server = $this->serverAnswering([
            ['status' => 200, 'body' => ['kind' => 'full', 'configs' => ['flag' => ['value' => 'served']]]],
        ]);
        mkdir($this->storeFolder() . '/payload.json');

        $storedPayload = $this->transportPolling($server)->storedPayloadForInitialization();

        self::assertNull($storedPayload);
        self::assertCount(1, $server->requestsReceived());
    }

    public function testAPayloadTheStoreCannotHoldIsNotPolledForAgainInsideTheInterval(): void
    {
        $server = $this->serverAnswering([
            ['status' => 200, 'body' => ['kind' => 'full', 'configs' => []]],
            ['status' => 200, 'body' => ['kind' => 'full', 'configs' => []]],
        ]);
        mkdir($this->storeFolder() . '/payload.json');
        $this->transportPolling($server)->storedPayloadForInitialization();

        $this->clock->epochMilliseconds = self::NOVEMBER_12_2026_AT_NOON + 59_999;
        $this->transportPolling($server)->storedPayloadForInitialization();

        self::assertCount(1, $server->requestsReceived());
    }

    public function testNoPollIsSentWhenTheStoreCannotRecordThatItWasSent(): void
    {
        $server = $this->serverAnswering([['status' => 200, 'body' => ['kind' => 'full', 'configs' => []]]]);
        $pollingPauseFile = $this->pollingPauseFileOf($server);
        mkdir($pollingPauseFile);
        $transport = $this->transportPolling($server);

        $storedPayload = $transport->storedPayloadForInitialization();

        self::assertNull($storedPayload);
        self::assertSame([], $server->requestsReceived());
        self::assertSame([['file' => $pollingPauseFile]], $this->logger->contextsLoggedAt(LogLevel::ERROR));
    }

    public function testA401IsNotSentAgainAndLogsOneErrorWithItsStatus(): void
    {
        $server = $this->serverAnswering([
            ['status' => 401, 'body' => 'Unauthorized'],
            ['status' => 200, 'body' => ['kind' => 'full', 'configs' => []]],
        ]);
        $transport = $this->transportPolling($server);

        $storedPayload = $transport->storedPayloadForInitialization();

        self::assertNull($storedPayload);
        self::assertCount(1, $server->requestsReceived());
        self::assertSame(401, $this->statusOfTheOneErrorLogged());
    }

    public function testA401IsReportedAsAnUnrecoverableErrorWithItsStatus(): void
    {
        $server = $this->serverAnswering([['status' => 401, 'body' => 'Unauthorized']]);
        $transport = $this->transportPolling($server);

        $transport->storedPayloadForInitialization();

        self::assertCount(1, $this->unrecoverableErrorsReported);
        self::assertSame(401, $this->unrecoverableErrorsReported[0]->status);
    }

    public function testTheErrorOfA401CarriesWhatTheServerAnsweredWithoutTheServerSdkKey(): void
    {
        $server = $this->serverAnswering([['status' => 401, 'body' => 'The key server-sdk-key is not valid']]);
        $transport = $this->transportPolling($server);

        $transport->storedPayloadForInitialization();

        $message = $this->unrecoverableErrorsReported[0]->getMessage();
        self::assertStringContainsString('is not valid', $message);
        self::assertStringNotContainsString('server-sdk-key', $message);
    }

    public function testAfterA401NoRequestPollsBeforeOneHourHasPassed(): void
    {
        $server = $this->serverAnswering([
            ['status' => 401, 'body' => 'Unauthorized'],
            ['status' => 200, 'body' => ['kind' => 'full', 'configs' => []]],
        ]);
        $this->transportPolling($server)->storedPayloadForInitialization();

        $this->clock->epochMilliseconds = self::NOVEMBER_12_2026_AT_NOON + 3_599_999;
        $storedPayload = $this->transportPolling($server)->storedPayloadForInitialization();

        self::assertNull($storedPayload);
        self::assertCount(1, $server->requestsReceived());
    }

    public function testAfterA401TheFirstRequestAfterOneHourPollsAgain(): void
    {
        $server = $this->serverAnswering([
            ['status' => 401, 'body' => 'Unauthorized'],
            ['status' => 200, 'body' => ['kind' => 'full', 'configs' => ['flag' => ['value' => 'served']]]],
        ]);
        $this->transportPolling($server)->storedPayloadForInitialization();

        $this->clock->epochMilliseconds = self::NOVEMBER_12_2026_AT_NOON + 3_600_000;
        $storedPayload = $this->transportPolling($server)->storedPayloadForInitialization();

        self::assertSame(['kind' => 'full', 'configs' => ['flag' => ['value' => 'served']]], $storedPayload?->payload);
        self::assertCount(2, $server->requestsReceived());
    }

    public function testAfterA404ARequestThatPollsAnotherUrlPollsAtOnce(): void
    {
        $refusingServer = $this->serverAnswering([['status' => 404, 'body' => 'Not Found']]);
        $correctedServer = $this->serverAnswering([
            ['status' => 200, 'body' => ['kind' => 'full', 'configs' => ['flag' => ['value' => 'served']]]],
        ]);
        $this->transportPolling($refusingServer)->storedPayloadForInitialization();

        $this->clock->epochMilliseconds = self::NOVEMBER_12_2026_AT_NOON + 1_000;
        $storedPayload = $this->transportPolling($correctedServer)->storedPayloadForInitialization();

        self::assertSame(['kind' => 'full', 'configs' => ['flag' => ['value' => 'served']]], $storedPayload?->payload);
        self::assertCount(1, $correctedServer->requestsReceived());
    }

    public function testA503IsNotReportedAsAnUnrecoverableError(): void
    {
        $server = $this->serverAnswering([['status' => 503]]);
        $transport = $this->transportPolling($server);

        $transport->storedPayloadForInitialization();

        self::assertSame([], $this->unrecoverableErrorsReported);
    }

    public function testA429LogsOneErrorWithItsStatusAndIsNotReportedAsAnUnrecoverableError(): void
    {
        $server = $this->serverAnswering([
            ['status' => 429, 'headers' => ['Retry-After' => '120'], 'body' => 'Service suspended'],
        ]);
        $transport = $this->transportPolling($server);

        $transport->storedPayloadForInitialization();

        self::assertSame(429, $this->statusOfTheOneErrorLogged());
        self::assertSame([], $this->unrecoverableErrorsReported);
    }

    public function testAfterA429WithARetryAfterOfTwoMinutesNoRequestPollsBeforeTwoMinutesHavePassed(): void
    {
        $server = $this->serverAnswering([
            ['status' => 429, 'headers' => ['Retry-After' => '120'], 'body' => 'Service suspended'],
            ['status' => 200, 'body' => ['kind' => 'full', 'configs' => []]],
        ]);
        $this->transportPolling($server)->storedPayloadForInitialization();

        $this->clock->epochMilliseconds = self::NOVEMBER_12_2026_AT_NOON + 119_999;
        $storedPayload = $this->transportPolling($server)->storedPayloadForInitialization();

        self::assertNull($storedPayload);
        self::assertCount(1, $server->requestsReceived());
    }

    public function testAfterA429WithARetryAfterOfTwoMinutesTheFirstRequestAfterTwoMinutesPollsAgain(): void
    {
        $server = $this->serverAnswering([
            ['status' => 429, 'headers' => ['Retry-After' => '120'], 'body' => 'Service suspended'],
            ['status' => 200, 'body' => ['kind' => 'full', 'configs' => []]],
        ]);
        $this->transportPolling($server)->storedPayloadForInitialization();

        $this->clock->epochMilliseconds = self::NOVEMBER_12_2026_AT_NOON + 120_000;
        $this->transportPolling($server)->storedPayloadForInitialization();

        self::assertCount(2, $server->requestsReceived());
    }

    public function testAfterA429WithARetryAfterDateTwoMinutesAheadNoRequestPollsBeforeThatDate(): void
    {
        $server = $this->serverAnswering([
            ['status' => 429, 'headers' => ['Retry-After' => 'Thu, 12 Nov 2026 12:02:00 GMT']],
            ['status' => 200, 'body' => ['kind' => 'full', 'configs' => []]],
        ]);
        $this->transportPolling($server)->storedPayloadForInitialization();

        $this->clock->epochMilliseconds = self::NOVEMBER_12_2026_AT_NOON + 119_999;
        $this->transportPolling($server)->storedPayloadForInitialization();

        self::assertCount(1, $server->requestsReceived());
    }

    public function testAfterA429WithARetryAfterDateTwoMinutesAheadTheFirstRequestAfterThatDatePollsAgain(): void
    {
        $server = $this->serverAnswering([
            ['status' => 429, 'headers' => ['Retry-After' => 'Thu, 12 Nov 2026 12:02:00 GMT']],
            ['status' => 200, 'body' => ['kind' => 'full', 'configs' => []]],
        ]);
        $this->transportPolling($server)->storedPayloadForInitialization();

        $this->clock->epochMilliseconds = self::NOVEMBER_12_2026_AT_NOON + 120_000;
        $this->transportPolling($server)->storedPayloadForInitialization();

        self::assertCount(2, $server->requestsReceived());
    }

    public function testAfterA429WithARetryAfterShorterThanThePollingIntervalNoRequestPollsBeforeTheInterval(): void
    {
        $server = $this->serverAnswering([
            ['status' => 429, 'headers' => ['Retry-After' => '30']],
            ['status' => 200, 'body' => ['kind' => 'full', 'configs' => []]],
        ]);
        $this->transportPolling($server)->storedPayloadForInitialization();

        $this->clock->epochMilliseconds = self::NOVEMBER_12_2026_AT_NOON + 59_999;
        $this->transportPolling($server)->storedPayloadForInitialization();

        self::assertCount(1, $server->requestsReceived());
    }

    public function testAfterA429WithoutARetryAfterTheFirstRequestAfterTheIntervalPollsAgain(): void
    {
        $server = $this->serverAnswering([
            ['status' => 429],
            ['status' => 200, 'body' => ['kind' => 'full', 'configs' => []]],
        ]);
        $this->transportPolling($server)->storedPayloadForInitialization();

        $this->clock->epochMilliseconds = self::NOVEMBER_12_2026_AT_NOON + 60_000;
        $this->transportPolling($server)->storedPayloadForInitialization();

        self::assertCount(2, $server->requestsReceived());
    }

    public function testAfterA429WithARetryAfterOfOneDayNoRequestPollsBeforeOneHourHasPassed(): void
    {
        $server = $this->serverAnswering([
            ['status' => 429, 'headers' => ['Retry-After' => '86400']],
            ['status' => 200, 'body' => ['kind' => 'full', 'configs' => []]],
        ]);
        $this->transportPolling($server)->storedPayloadForInitialization();

        $this->clock->epochMilliseconds = self::NOVEMBER_12_2026_AT_NOON + 3_599_999;
        $this->transportPolling($server)->storedPayloadForInitialization();

        self::assertCount(1, $server->requestsReceived());
    }

    public function testAfterA429WithARetryAfterOfOneDayTheFirstRequestAfterOneHourPollsAgain(): void
    {
        $server = $this->serverAnswering([
            ['status' => 429, 'headers' => ['Retry-After' => '86400']],
            ['status' => 200, 'body' => ['kind' => 'full', 'configs' => []]],
        ]);
        $this->transportPolling($server)->storedPayloadForInitialization();

        $this->clock->epochMilliseconds = self::NOVEMBER_12_2026_AT_NOON + 3_600_000;
        $this->transportPolling($server)->storedPayloadForInitialization();

        self::assertCount(2, $server->requestsReceived());
    }

    public function testAClientYoungerThanTheFlushIntervalSendsNoPollDuringARead(): void
    {
        $server = $this->serverAnswering([['status' => 204]]);
        $this->storePayload(['kind' => 'full', 'configs' => []], self::NOVEMBER_12_2026_AT_NOON - 60_000);
        $transport = $this->transportPolling($server, flushIntervalMilliseconds: 30_000);
        $transport->storedPayloadForInitialization();

        $this->clock->epochMilliseconds = self::NOVEMBER_12_2026_AT_NOON + 30_000;
        $transport->pollWhenDueInALongRunningProcess();

        self::assertSame([], $server->requestsReceived());
    }

    public function testAClientOlderThanTheFlushIntervalPollsInTheReadThatFindsTheStoreStale(): void
    {
        $server = $this->serverAnswering([
            ['status' => 200, 'body' => ['kind' => 'full', 'configs' => ['flag' => ['value' => 'new']]]],
        ]);
        $this->storePayload(['kind' => 'full', 'configs' => []], self::NOVEMBER_12_2026_AT_NOON - 60_000);
        $transport = $this->transportPolling($server, flushIntervalMilliseconds: 30_000);
        $transport->storedPayloadForInitialization();

        $this->clock->epochMilliseconds = self::NOVEMBER_12_2026_AT_NOON + 30_001;
        $transport->pollWhenDueInALongRunningProcess();

        self::assertCount(1, $server->requestsReceived());
        self::assertSame(
            ['kind' => 'full', 'configs' => ['flag' => ['value' => 'new']]],
            $this->storeOpenedAgain()->storedPayload()?->payload,
        );
    }

    public function testALongRunningProcessSendsNoSecondPollInsideTheInterval(): void
    {
        $server = $this->serverAnswering([['status' => 204], ['status' => 204]]);
        $this->storePayload(['kind' => 'full', 'configs' => []], self::NOVEMBER_12_2026_AT_NOON - 60_000);
        $transport = $this->transportPolling($server, flushIntervalMilliseconds: 30_000);
        $transport->storedPayloadForInitialization();
        $this->clock->epochMilliseconds = self::NOVEMBER_12_2026_AT_NOON + 30_001;
        $transport->pollWhenDueInALongRunningProcess();

        $this->clock->epochMilliseconds = self::NOVEMBER_12_2026_AT_NOON + 90_000;
        $transport->pollWhenDueInALongRunningProcess();

        self::assertCount(1, $server->requestsReceived());
    }

    public function testALongRunningProcessPollsAgainOnceTheNextIntervalHasPassed(): void
    {
        $server = $this->serverAnswering([['status' => 204], ['status' => 204]]);
        $this->storePayload(['kind' => 'full', 'configs' => []], self::NOVEMBER_12_2026_AT_NOON - 60_000);
        $transport = $this->transportPolling($server, flushIntervalMilliseconds: 30_000);
        $transport->storedPayloadForInitialization();
        $this->clock->epochMilliseconds = self::NOVEMBER_12_2026_AT_NOON + 30_001;
        $transport->pollWhenDueInALongRunningProcess();

        $this->clock->epochMilliseconds = self::NOVEMBER_12_2026_AT_NOON + 90_001;
        $transport->pollWhenDueInALongRunningProcess();

        self::assertCount(2, $server->requestsReceived());
    }

    public function testALongRunningProcessWhosePollFailedSendsNoSecondPollInsideTheInterval(): void
    {
        $server = $this->serverAnswering([['status' => 503], ['status' => 204]]);
        $this->storePayload(['kind' => 'full', 'configs' => []], self::NOVEMBER_12_2026_AT_NOON - 60_000);
        $transport = $this->transportPolling($server, flushIntervalMilliseconds: 30_000);
        $transport->storedPayloadForInitialization();
        $this->clock->epochMilliseconds = self::NOVEMBER_12_2026_AT_NOON + 30_001;
        $transport->pollWhenDueInALongRunningProcess();

        $this->clock->epochMilliseconds = self::NOVEMBER_12_2026_AT_NOON + 90_000;
        $transport->pollWhenDueInALongRunningProcess();

        self::assertCount(1, $server->requestsReceived());
    }

    public function testALongRunningProcessThatFindsAnotherProcessPollingSendsNoPollOfItsOwnInsideTheInterval(): void
    {
        $server = $this->serverAnswering([['status' => 204]]);
        $this->storePayload(['kind' => 'full', 'configs' => []], self::NOVEMBER_12_2026_AT_NOON - 60_000);
        $transport = $this->transportPolling($server, flushIntervalMilliseconds: 30_000);
        $transport->storedPayloadForInitialization();
        $this->clock->epochMilliseconds = self::NOVEMBER_12_2026_AT_NOON + 30_001;
        $lockOfTheProcessThatPolls = $this->storeOpenedAgain()->refreshLockAcquiredWithin(0);
        self::assertNotNull($lockOfTheProcessThatPolls);
        $transport->pollWhenDueInALongRunningProcess();
        $lockOfTheProcessThatPolls->release();

        $this->clock->epochMilliseconds = self::NOVEMBER_12_2026_AT_NOON + 90_000;
        $transport->pollWhenDueInALongRunningProcess();

        self::assertSame([], $server->requestsReceived());
    }

    public function testALongRunningProcessThatFoundAnotherProcessPollingPollsOnceAnIntervalHasPassed(): void
    {
        $server = $this->serverAnswering([['status' => 204]]);
        $this->storePayload(['kind' => 'full', 'configs' => []], self::NOVEMBER_12_2026_AT_NOON - 60_000);
        $transport = $this->transportPolling($server, flushIntervalMilliseconds: 30_000);
        $transport->storedPayloadForInitialization();
        $this->clock->epochMilliseconds = self::NOVEMBER_12_2026_AT_NOON + 30_001;
        $lockOfTheProcessThatPolls = $this->storeOpenedAgain()->refreshLockAcquiredWithin(0);
        self::assertNotNull($lockOfTheProcessThatPolls);
        $transport->pollWhenDueInALongRunningProcess();
        $lockOfTheProcessThatPolls->release();

        $this->clock->epochMilliseconds = self::NOVEMBER_12_2026_AT_NOON + 90_001;
        $transport->pollWhenDueInALongRunningProcess();

        self::assertCount(1, $server->requestsReceived());
    }

    public function testAClosedTransportSendsNoPollDuringARead(): void
    {
        $server = $this->serverAnswering([['status' => 204]]);
        $this->storePayload(['kind' => 'full', 'configs' => []], self::NOVEMBER_12_2026_AT_NOON);
        $transport = $this->transportPolling($server, flushIntervalMilliseconds: 30_000);
        $transport->storedPayloadForInitialization();
        $transport->close();

        $this->clock->epochMilliseconds = self::NOVEMBER_12_2026_AT_NOON + 60_000;
        $transport->pollWhenDueInALongRunningProcess();

        self::assertSame([], $server->requestsReceived());
    }

    public function testAStoreFolderThatIsRefusedIsNeverPolledFor(): void
    {
        $server = $this->serverAnswering([['status' => 200, 'body' => ['kind' => 'full', 'configs' => []]]]);
        $regularFile = $this->storagePath . DIRECTORY_SEPARATOR . 'regular-file';
        touch($regularFile);
        $transport = new PollingTransport(
            LocalStore::ofServerSdkKey('server-sdk-key', $regularFile, $this->logger),
            new PollingEndpoint($server->url, 'server-sdk-key', new Metadata()),
            PollingInterval::ofConfiguredSeconds(60),
            3_000,
            30_000,
            $this->clock,
            $this->logger,
            static function (): void {},
        );

        $storedPayload = $transport->storedPayloadForInitialization();
        $transport->close();

        self::assertNull($storedPayload);
        self::assertSame([], $server->requestsReceived());
    }

    public function testAStoredPayloadMarkedFreshByAClockThatWasSetBackSinceIsPolledForAtOnce(): void
    {
        $server = $this->serverAnswering([['status' => 204]]);
        $this->storePayload(['kind' => 'full', 'configs' => []], self::NOVEMBER_12_2026_AT_NOON + 86_400_000);
        $transport = $this->transportPolling($server);
        $transport->storedPayloadForInitialization();

        $transport->close();

        self::assertCount(1, $server->requestsReceived());
    }

    public function testAPollingPauseRecordedByAClockThatWasSetBackSinceHoldsNoPoll(): void
    {
        $server = $this->serverAnswering([
            ['status' => 503],
            ['status' => 200, 'body' => ['kind' => 'full', 'configs' => []]],
        ]);
        $this->clock->epochMilliseconds = self::NOVEMBER_12_2026_AT_NOON + 86_400_000;
        $this->transportPolling($server)->storedPayloadForInitialization();

        $this->clock->epochMilliseconds = self::NOVEMBER_12_2026_AT_NOON;
        $this->transportPolling($server)->storedPayloadForInitialization();

        self::assertCount(2, $server->requestsReceived());
    }

    public function testAfterA429WithARetryAfterOfTwentyDigitsNoRequestPollsBeforeOneHourHasPassed(): void
    {
        $server = $this->serverAnswering([
            ['status' => 429, 'headers' => ['Retry-After' => '99999999999999999999']],
            ['status' => 200, 'body' => ['kind' => 'full', 'configs' => []]],
        ]);
        $this->transportPolling($server)->storedPayloadForInitialization();

        $this->clock->epochMilliseconds = self::NOVEMBER_12_2026_AT_NOON + 3_599_999;
        $this->transportPolling($server)->storedPayloadForInitialization();

        self::assertCount(1, $server->requestsReceived());
    }

    public function testAfterA429WithARetryAfterOfTwentyDigitsTheFirstRequestAfterOneHourPollsAgain(): void
    {
        $server = $this->serverAnswering([
            ['status' => 429, 'headers' => ['Retry-After' => '99999999999999999999']],
            ['status' => 200, 'body' => ['kind' => 'full', 'configs' => []]],
        ]);
        $this->transportPolling($server)->storedPayloadForInitialization();

        $this->clock->epochMilliseconds = self::NOVEMBER_12_2026_AT_NOON + 3_600_000;
        $this->transportPolling($server)->storedPayloadForInitialization();

        self::assertCount(2, $server->requestsReceived());
    }

    public function testAfterA429WithARetryAfterThatIsNeitherSecondsNorADateTheFirstRequestAfterTheIntervalPollsAgain(): void
    {
        $server = $this->serverAnswering([
            ['status' => 429, 'headers' => ['Retry-After' => 'in two minutes']],
            ['status' => 200, 'body' => ['kind' => 'full', 'configs' => []]],
        ]);
        $this->transportPolling($server)->storedPayloadForInitialization();

        $this->clock->epochMilliseconds = self::NOVEMBER_12_2026_AT_NOON + 60_000;
        $this->transportPolling($server)->storedPayloadForInitialization();

        self::assertCount(2, $server->requestsReceived());
    }

    public function testAfterA429WithARetryAfterDateInThePastNoRequestPollsBeforeTheInterval(): void
    {
        $server = $this->serverAnswering([
            ['status' => 429, 'headers' => ['Retry-After' => 'Thu, 12 Nov 2026 11:00:00 GMT']],
            ['status' => 200, 'body' => ['kind' => 'full', 'configs' => []]],
        ]);
        $this->transportPolling($server)->storedPayloadForInitialization();

        $this->clock->epochMilliseconds = self::NOVEMBER_12_2026_AT_NOON + 59_999;
        $this->transportPolling($server)->storedPayloadForInitialization();

        self::assertCount(1, $server->requestsReceived());
    }

    public function testAfterA429WithARetryAfterDateInThePastTheFirstRequestAfterTheIntervalPollsAgain(): void
    {
        $server = $this->serverAnswering([
            ['status' => 429, 'headers' => ['Retry-After' => 'Thu, 12 Nov 2026 11:00:00 GMT']],
            ['status' => 200, 'body' => ['kind' => 'full', 'configs' => []]],
        ]);
        $this->transportPolling($server)->storedPayloadForInitialization();

        $this->clock->epochMilliseconds = self::NOVEMBER_12_2026_AT_NOON + 60_000;
        $this->transportPolling($server)->storedPayloadForInitialization();

        self::assertCount(2, $server->requestsReceived());
    }

    public function testTheErrorOfA401CarriesNoMoreThanTheFirstKibibyteOfWhatTheServerAnswered(): void
    {
        $server = $this->serverAnswering([['status' => 401, 'body' => str_repeat('z', 5_000)]]);
        $transport = $this->transportPolling($server);

        $transport->storedPayloadForInitialization();

        self::assertSame(1_024, substr_count($this->unrecoverableErrorsReported[0]->getMessage(), 'z'));
    }

    public function testADeltaReceivedByAnEmptyStoreIsStoredAsItWasReceived(): void
    {
        $server = $this->serverAnswering([
            ['status' => 200, 'body' => ['kind' => 'delta', 'configs' => ['flag' => ['value' => 'served']]]],
        ]);
        $transport = $this->transportPolling($server);

        $transport->storedPayloadForInitialization();

        self::assertSame(
            ['kind' => 'delta', 'configs' => ['flag' => ['value' => 'served']]],
            $this->storeOpenedAgain()->storedPayload()?->payload,
        );
    }

    public function testThePollSentAgainIsGivenOnlyWhatTheFirstAttemptLeftOfTheTimeLimit(): void
    {
        $server = $this->serverAnswering([
            ['closes' => 'before-answering', 'delayMilliseconds' => 200],
            ['status' => 200, 'body' => ['kind' => 'full', 'configs' => []], 'delayMilliseconds' => 400],
        ]);
        $transport = $this->transportPolling($server, timeoutMilliseconds: 500);

        $storedPayload = $transport->storedPayloadForInitialization();

        self::assertNull($storedPayload);
        self::assertCount(2, $server->requestsReceived());
    }

    public function testAPollOfAnEmptyStoreIsGivenOnlyWhatWaitingForTheRefreshLockLeftOfTheTimeout(): void
    {
        $server = $this->serverAnswering([
            ['status' => 200, 'body' => ['kind' => 'full', 'configs' => []], 'delayMilliseconds' => 350],
        ]);
        $transport = $this->transportPolling($server, timeoutMilliseconds: 500);
        $processHoldingTheRefreshLock = PhpProcess::running(
            dirname(__DIR__) . '/LocalStore/Processes/keep-the-refresh-lock.php',
            [$this->storagePath, 'server-sdk-key', '300'],
        );
        $lineOfTheHolder = $processHoldingTheRefreshLock->nextLineOfOutput();

        $storedPayload = $transport->storedPayloadForInitialization();
        $processHoldingTheRefreshLock->finished();

        self::assertSame('locked', $lineOfTheHolder);
        self::assertNull($storedPayload);
        self::assertCount(1, $server->requestsReceived());
    }

    public function testA401IsRecordedInTheStoreWithItsStatus(): void
    {
        $server = $this->serverAnswering([['status' => 401, 'body' => 'Unauthorized']]);

        $this->transportPolling($server)->storedPayloadForInitialization();

        self::assertSame(401, $this->pollingPauseOf($server)?->refusalStatus);
    }

    public function testA429IsRecordedInTheStoreWithItsStatus(): void
    {
        $server = $this->serverAnswering([['status' => 429, 'headers' => ['Retry-After' => '120']]]);

        $this->transportPolling($server)->storedPayloadForInitialization();

        self::assertSame(429, $this->pollingPauseOf($server)?->refusalStatus);
    }

    public function testAPollThatGotNoAnswerIsRecordedInTheStoreAsFailedWithoutAStatus(): void
    {
        $server = $this->serverAnswering([['closes' => 'before-answering'], ['closes' => 'before-answering']]);

        $this->transportPolling($server)->storedPayloadForInitialization();

        self::assertEquals(
            new PollingPause(
                $this->sessionIdOfTheFirstPollReceivedBy($server),
                self::NOVEMBER_12_2026_AT_NOON,
                self::NOVEMBER_12_2026_AT_NOON + 60_000,
                true,
                null,
            ),
            $this->pollingPauseOf($server),
        );
    }

    public function testAfterA401WithAPollingIntervalOfTwoHoursNoRequestPollsBeforeTwoHoursHavePassed(): void
    {
        $server = $this->serverAnswering([
            ['status' => 401, 'body' => 'Unauthorized'],
            ['status' => 200, 'body' => ['kind' => 'full', 'configs' => []]],
        ]);
        $this->transportPolling($server, configuredIntervalSeconds: 7_200)->storedPayloadForInitialization();

        $this->clock->epochMilliseconds = self::NOVEMBER_12_2026_AT_NOON + 7_199_999;
        $this->transportPolling($server, configuredIntervalSeconds: 7_200)->storedPayloadForInitialization();

        self::assertCount(1, $server->requestsReceived());
    }

    public function testAfterTwoUrlsRefusedTheSameKeyNeitherIsPolledAgainInsideTheHour(): void
    {
        $firstServer = $this->serverAnswering([['status' => 401], ['status' => 401]]);
        $secondServer = $this->serverAnswering([['status' => 401], ['status' => 401]]);
        $this->transportPolling($firstServer)->storedPayloadForInitialization();
        $this->transportPolling($secondServer)->storedPayloadForInitialization();

        $this->clock->epochMilliseconds = self::NOVEMBER_12_2026_AT_NOON + 3_599_999;
        $this->transportPolling($firstServer)->storedPayloadForInitialization();
        $this->transportPolling($secondServer)->storedPayloadForInitialization();

        self::assertCount(1, $firstServer->requestsReceived());
        self::assertCount(1, $secondServer->requestsReceived());
    }

    public function testARequestThatFindsAnEmptyStorePausedAfterAFailedPollDoesNotWaitForTheRefreshLock(): void
    {
        $server = $this->serverAnswering([['status' => 503]]);
        $this->transportPolling($server)->storedPayloadForInitialization();
        $lockOfAnotherProcess = $this->storeOpenedAgain()->refreshLockAcquiredWithin(0);
        self::assertNotNull($lockOfAnotherProcess);
        $startedAt = hrtime(true);

        $storedPayload = $this->transportPolling($server, timeoutMilliseconds: 2_000)->storedPayloadForInitialization();
        $waitedMilliseconds = intdiv(hrtime(true) - $startedAt, 1_000_000);
        $lockOfAnotherProcess->release();

        self::assertNull($storedPayload);
        self::assertLessThan(1_000, $waitedMilliseconds);
    }

    public function testARequestThatFindsAnEmptyStoreWhileAnotherProcessPollsWaitsForThePayloadOfThatPoll(): void
    {
        $server = $this->serverAnswering([['status' => 200, 'body' => ['kind' => 'full', 'configs' => []]]]);
        $refreshLock = $this->storeOpenedAgain()->refreshLockAcquiredWithin(0);
        self::assertNotNull($refreshLock);
        $refreshLock->recordPollingPause(
            $server->url . '/server/polling/v1',
            new PollingPause(
                'session-1',
                self::NOVEMBER_12_2026_AT_NOON,
                self::NOVEMBER_12_2026_AT_NOON + 60_000,
                false,
                null,
            ),
        );
        $refreshLock->release();
        $processThatPolls = PhpProcess::running(
            dirname(__DIR__) . '/LocalStore/Processes/store-payload-while-keeping-the-lock.php',
            [$this->storagePath, 'server-sdk-key', '150', '50'],
        );
        $lineOfTheProcessThatPolls = $processThatPolls->nextLineOfOutput();

        $storedPayload = $this->transportPolling($server)->storedPayloadForInitialization();
        $processThatPolls->finished();

        self::assertSame('locked', $lineOfTheProcessThatPolls);
        self::assertSame(['marker' => 'stored-by-the-holder'], $storedPayload?->payload);
        self::assertSame([], $server->requestsReceived());
    }

    public function testTheRefreshLockIsFreeWhenAnUnrecoverableErrorOfAnEmptyStoreIsReported(): void
    {
        $server = $this->serverAnswering([['status' => 401, 'body' => 'Unauthorized']]);
        $transport = $this->transportPolling($server);

        $transport->storedPayloadForInitialization();

        self::assertSame([true], $this->refreshLockWasFreeWhileReporting);
    }

    public function testTheRefreshLockIsFreeWhenAnUnrecoverableErrorAtTheEndOfARequestIsReported(): void
    {
        $server = $this->serverAnswering([['status' => 401, 'body' => 'Unauthorized']]);
        $this->storePayload(['kind' => 'full', 'configs' => []], self::NOVEMBER_12_2026_AT_NOON - 60_000);
        $transport = $this->transportPolling($server);
        $transport->storedPayloadForInitialization();

        $transport->close();

        self::assertSame([true], $this->refreshLockWasFreeWhileReporting);
    }

    public function testALongRunningProcessWhoseWallClockIsSetBackStillPollsOnceTheIntervalHasPassed(): void
    {
        $server = $this->serverAnswering([['status' => 204]]);
        $this->storePayload(['kind' => 'full', 'configs' => []], self::NOVEMBER_12_2026_AT_NOON);
        $transport = $this->transportPolling($server, flushIntervalMilliseconds: 30_000);
        $transport->storedPayloadForInitialization();
        $this->clock->setTheWallClockBackBy(86_400_000);

        $this->clock->epochMilliseconds += 60_000;
        $transport->pollWhenDueInALongRunningProcess();

        self::assertCount(1, $server->requestsReceived());
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

    private function transportPolling(
        ScriptedSdkServer $server,
        int $configuredIntervalSeconds = 60,
        int $timeoutMilliseconds = 3_000,
        int $flushIntervalMilliseconds = 30_000,
        ?Metadata $metadata = null,
    ): PollingTransport {
        return new PollingTransport(
            $this->storeOpenedAgain(),
            new PollingEndpoint($server->url, 'server-sdk-key', $metadata ?? new Metadata()),
            PollingInterval::ofConfiguredSeconds($configuredIntervalSeconds),
            $timeoutMilliseconds,
            $flushIntervalMilliseconds,
            $this->clock,
            $this->logger,
            function (ConfigDirectorConnectionException $error): void {
                $this->unrecoverableErrorsReported[] = $error;
                $refreshLock = $this->storeOpenedAgain()->refreshLockAcquiredWithin(0);
                $this->refreshLockWasFreeWhileReporting[] = $refreshLock !== null;
                $refreshLock?->release();
            },
        );
    }

    private function statusOfTheOneErrorLogged(): ?int
    {
        $contexts = $this->logger->contextsLoggedAt(LogLevel::ERROR);
        self::assertCount(1, $contexts);
        $exception = $contexts[0]['exception'] ?? null;
        self::assertInstanceOf(ConfigDirectorConnectionException::class, $exception);

        return $exception->status;
    }

    private function pollingPauseFileOf(ScriptedSdkServer $server): string
    {
        return $this->storeFolder()
            . DIRECTORY_SEPARATOR . 'polling-pause-' . hash('sha256', $server->url . '/server/polling/v1') . '.json';
    }

    private function pollingPauseOf(ScriptedSdkServer $server): ?PollingPause
    {
        return $this->storeOpenedAgain()->pollingPause($server->url . '/server/polling/v1');
    }

    private function sessionIdOfTheFirstPollReceivedBy(ScriptedSdkServer $server): string
    {
        $body = $server->requestsReceived()[0]['body'];
        self::assertIsArray($body);
        self::assertIsString($body['sessionId']);

        return $body['sessionId'];
    }

    private function storeFolder(): string
    {
        $this->storeOpenedAgain();

        return $this->storagePath
            . DIRECTORY_SEPARATOR . 'add45d3fbae45285dcd74320ecebb931a054c563edc414cd120865f8ff4e09f8';
    }

    private function storeOpenedAgain(): LocalStore
    {
        return LocalStore::ofServerSdkKey('server-sdk-key', $this->storagePath, $this->logger);
    }

    /**
     * @param array<array-key, mixed> $payload
     */
    private function storePayload(array $payload, int $confirmedFreshAtEpochMilliseconds): void
    {
        $refreshLock = $this->storeOpenedAgain()->refreshLockAcquiredWithin(0);
        self::assertNotNull($refreshLock);
        $refreshLock->replaceStoredPayload(new StoredPayload($payload, $confirmedFreshAtEpochMilliseconds, 'session-1'));
        $refreshLock->release();
    }
}
