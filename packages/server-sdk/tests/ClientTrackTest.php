<?php

declare(strict_types=1);

namespace ConfigDirector\Tests;

use ConfigDirector\ConfigDirector;
use ConfigDirector\ConfigDirectorClient;
use ConfigDirector\ConnectionOptions;
use ConfigDirector\Context;
use ConfigDirector\TelemetryOptions;
use ConfigDirector\Tests\Support\RecordingLogger;
use ConfigDirector\Tests\Support\ScriptedSdkServer;
use ConfigDirector\Tests\Support\TemporaryFolder;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

final class ClientTrackTest extends TestCase
{
    private string $storagePath;

    private RecordingLogger $logger;

    private ?ScriptedSdkServer $server = null;

    #[\Override]
    protected function setUp(): void
    {
        $this->storagePath = TemporaryFolder::create();
        $this->logger = new RecordingLogger();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->server?->stop();
        TemporaryFolder::remove($this->storagePath);
    }

    public function testATrackedEventArrivesInTheNextReportWithItsContextValueDetailsAndWhenItHappened(): void
    {
        $this->startSdkServer();
        $trackedAfter = new \DateTimeImmutable();
        $client = $this->initializedClient();
        $client->track(
            'checkout-completed',
            new Context(id: 'org-7', kind: 'organization'),
            value: 49.9,
            details: ['plan' => 'pro', 'seats' => 3],
        );
        $trackedBefore = new \DateTimeImmutable();
        $client->dispose();

        $this->endARequestAfterTheFlushInterval();

        [$trackedEvent] = $this->trackedEventsOfTheOnlyReport();
        self::assertIsArray($trackedEvent);
        self::assertIsString($trackedEvent['happenedAt']);
        self::assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/',
            $trackedEvent['happenedAt'],
        );
        $happenedAt = new \DateTimeImmutable($trackedEvent['happenedAt']);
        self::assertGreaterThanOrEqual($trackedAfter->modify('-1 millisecond'), $happenedAt);
        self::assertLessThanOrEqual($trackedBefore, $happenedAt);
        self::assertSame(
            [
                'name' => 'checkout-completed',
                'contextKind' => 'organization',
                'contextIdentifier' => 'org-7',
                'value' => 49.9,
                'details' => ['plan' => 'pro', 'seats' => 3],
            ],
            array_diff_key($trackedEvent, ['happenedAt' => true]),
        );
    }

    public function testAnEventWhoseNameHasOneHundredAndFiftyOneCharactersIsNotReportedAndLogsAWarning(): void
    {
        $this->startSdkServer();
        $client = $this->initializedClient();
        $client->track(str_repeat('n', 151));
        $client->track('checkout-completed');
        $client->dispose();

        $this->endARequestAfterTheFlushInterval();

        self::assertSame(['checkout-completed'], array_column($this->trackedEventsOfTheOnlyReport(), 'name'));
        self::assertCount(1, $this->logger->contextsLoggedAt(LogLevel::WARNING));
    }

    public function testAnEventWhoseNameHasOneHundredAndFiftyCharactersOfTwoBytesEachIsReported(): void
    {
        $this->startSdkServer();
        $client = $this->initializedClient();
        $client->track(str_repeat('é', 150));
        $client->dispose();

        $this->endARequestAfterTheFlushInterval();

        self::assertSame([str_repeat('é', 150)], array_column($this->trackedEventsOfTheOnlyReport(), 'name'));
        self::assertSame([], $this->logger->contextsLoggedAt(LogLevel::WARNING));
    }

    public function testAnEventWithAnEmptyNameIsNotReportedAndLogsAWarning(): void
    {
        $this->startSdkServer();
        $client = $this->initializedClient();
        $client->track('');
        $client->track('checkout-completed');
        $client->dispose();

        $this->endARequestAfterTheFlushInterval();

        self::assertSame(['checkout-completed'], array_column($this->trackedEventsOfTheOnlyReport(), 'name'));
        self::assertCount(1, $this->logger->contextsLoggedAt(LogLevel::WARNING));
    }

    public function testAnEventWhoseNameIsNotUtf8IsNotReportedAndLogsAWarning(): void
    {
        $this->startSdkServer();
        $client = $this->initializedClient();
        $client->track("checkout-\xFF");
        $client->track('checkout-completed');
        $client->dispose();

        $this->endARequestAfterTheFlushInterval();

        self::assertSame(['checkout-completed'], array_column($this->trackedEventsOfTheOnlyReport(), 'name'));
        self::assertCount(1, $this->logger->contextsLoggedAt(LogLevel::WARNING));
    }

    public function testAnEventWhoseValueIsInfinityIsReportedWithoutItsValueAndLogsAWarning(): void
    {
        $this->startSdkServer();
        $client = $this->initializedClient();
        $client->track('checkout-completed', value: INF);
        $client->dispose();

        $this->endARequestAfterTheFlushInterval();

        [$trackedEvent] = $this->trackedEventsOfTheOnlyReport();
        self::assertIsArray($trackedEvent);
        self::assertSame('checkout-completed', $trackedEvent['name']);
        self::assertArrayNotHasKey('value', $trackedEvent);
        self::assertCount(1, $this->logger->contextsLoggedAt(LogLevel::WARNING));
    }

    public function testAnEventWhoseValueIsNotANumberIsReportedWithoutItsValueAndLogsAWarning(): void
    {
        $this->startSdkServer();
        $client = $this->initializedClient();
        $client->track('checkout-completed', value: NAN);
        $client->dispose();

        $this->endARequestAfterTheFlushInterval();

        [$trackedEvent] = $this->trackedEventsOfTheOnlyReport();
        self::assertIsArray($trackedEvent);
        self::assertSame('checkout-completed', $trackedEvent['name']);
        self::assertArrayNotHasKey('value', $trackedEvent);
        self::assertCount(1, $this->logger->contextsLoggedAt(LogLevel::WARNING));
    }

    public function testAValueIsReportedAsTheNumberTextEverySdkWrites(): void
    {
        $this->startSdkServer();
        $client = $this->initializedClient();
        $client->track('dose-measured', value: 0.0000001);
        $client->dispose();

        $this->endARequestAfterTheFlushInterval();

        self::assertStringContainsString('"name":"dose-measured","value":1e-7,', $this->bodyTextOfTheOnlyReport());
    }

    public function testAnIntegerValueIsReported(): void
    {
        $this->startSdkServer();
        $client = $this->initializedClient();
        $client->track('items-added', value: 3);
        $client->dispose();

        $this->endARequestAfterTheFlushInterval();

        [$trackedEvent] = $this->trackedEventsOfTheOnlyReport();
        self::assertIsArray($trackedEvent);
        self::assertSame(3, $trackedEvent['value']);
        self::assertSame([], $this->logger->contextsLoggedAt(LogLevel::WARNING));
    }

    public function testAnEventWhoseDetailsHaveTwentyOneTopLevelKeysIsReportedWithoutThemAndLogsAWarning(): void
    {
        $this->startSdkServer();
        $client = $this->initializedClient();
        $details = [];
        foreach (range(1, 21) as $number) {
            $details["key-{$number}"] = $number;
        }
        $client->track('checkout-completed', value: 49.9, details: $details);
        $client->dispose();

        $this->endARequestAfterTheFlushInterval();

        [$trackedEvent] = $this->trackedEventsOfTheOnlyReport();
        self::assertIsArray($trackedEvent);
        self::assertSame('checkout-completed', $trackedEvent['name']);
        self::assertSame(49.9, $trackedEvent['value']);
        self::assertArrayNotHasKey('details', $trackedEvent);
        self::assertCount(1, $this->logger->contextsLoggedAt(LogLevel::WARNING));
    }

    public function testAnEventWhoseDetailsHaveTwentyTopLevelKeysIsReportedWithThem(): void
    {
        $this->startSdkServer();
        $client = $this->initializedClient();
        $details = [];
        foreach (range(1, 20) as $number) {
            $details["key-{$number}"] = $number;
        }
        $client->track('checkout-completed', details: $details);
        $client->dispose();

        $this->endARequestAfterTheFlushInterval();

        [$trackedEvent] = $this->trackedEventsOfTheOnlyReport();
        self::assertIsArray($trackedEvent);
        self::assertSame($details, $trackedEvent['details']);
        self::assertSame([], $this->logger->contextsLoggedAt(LogLevel::WARNING));
    }

    public function testAnEventWhoseDetailsAreOneThousandAndTwentyFourBytesAsJsonIsReportedWithThem(): void
    {
        $this->startSdkServer();
        $client = $this->initializedClient();
        $client->track('checkout-completed', details: ['note' => str_repeat('é', 506) . 'a']);
        $client->dispose();

        $this->endARequestAfterTheFlushInterval();

        [$trackedEvent] = $this->trackedEventsOfTheOnlyReport();
        self::assertIsArray($trackedEvent);
        self::assertSame(['note' => str_repeat('é', 506) . 'a'], $trackedEvent['details']);
        self::assertSame([], $this->logger->contextsLoggedAt(LogLevel::WARNING));
    }

    public function testAnEventWhoseDetailsAreOneThousandAndTwentyFiveBytesAsJsonIsReportedWithoutThem(): void
    {
        $this->startSdkServer();
        $client = $this->initializedClient();
        $client->track('checkout-completed', details: ['note' => str_repeat('é', 507)]);
        $client->dispose();

        $this->endARequestAfterTheFlushInterval();

        [$trackedEvent] = $this->trackedEventsOfTheOnlyReport();
        self::assertIsArray($trackedEvent);
        self::assertArrayNotHasKey('details', $trackedEvent);
        self::assertCount(1, $this->logger->contextsLoggedAt(LogLevel::WARNING));
    }

    public function testAnEventWhoseDetailsAreAListIsReportedWithoutThem(): void
    {
        $this->startSdkServer();
        $client = $this->initializedClient();
        $client->track('checkout-completed', details: ['pro', 'team']);
        $client->dispose();

        $this->endARequestAfterTheFlushInterval();

        [$trackedEvent] = $this->trackedEventsOfTheOnlyReport();
        self::assertIsArray($trackedEvent);
        self::assertArrayNotHasKey('details', $trackedEvent);
        self::assertCount(1, $this->logger->contextsLoggedAt(LogLevel::WARNING));
    }

    public function testAnEventWhoseDetailsAreAnEmptyArrayIsReportedWithAnEmptyObject(): void
    {
        $this->startSdkServer();
        $client = $this->initializedClient();
        $client->track('checkout-completed', details: []);
        $client->dispose();

        $this->endARequestAfterTheFlushInterval();

        self::assertStringContainsString('"name":"checkout-completed","details":{},', $this->bodyTextOfTheOnlyReport());
        self::assertSame([], $this->logger->contextsLoggedAt(LogLevel::WARNING));
    }

    public function testDetailsKeyedByNumbersAreReportedAsAnObject(): void
    {
        $this->startSdkServer();
        $client = $this->initializedClient();
        $client->track('checkout-completed', details: [2024 => 'renewal']);
        $client->dispose();

        $this->endARequestAfterTheFlushInterval();

        self::assertStringContainsString('"details":{"2024":"renewal"},', $this->bodyTextOfTheOnlyReport());
    }

    public function testAnEventWhoseDetailsHoldAnInfiniteNumberIsReportedWithoutThem(): void
    {
        $this->startSdkServer();
        $client = $this->initializedClient();
        $client->track('checkout-completed', details: ['discount' => ['rate' => INF]]);
        $client->dispose();

        $this->endARequestAfterTheFlushInterval();

        [$trackedEvent] = $this->trackedEventsOfTheOnlyReport();
        self::assertIsArray($trackedEvent);
        self::assertArrayNotHasKey('details', $trackedEvent);
        self::assertCount(1, $this->logger->contextsLoggedAt(LogLevel::WARNING));
    }

    public function testAnEventWhoseDetailsHoldAnObjectThatThrowsWhenWrittenAsJsonIsReportedWithoutThem(): void
    {
        $this->startSdkServer();
        $client = $this->initializedClient();
        $client->track('checkout-completed', details: ['coupon' => new class implements \JsonSerializable {
            #[\Override]
            public function jsonSerialize(): mixed
            {
                throw new \RuntimeException('The coupon cannot be written as JSON.');
            }
        }]);
        $client->dispose();

        $this->endARequestAfterTheFlushInterval();

        [$trackedEvent] = $this->trackedEventsOfTheOnlyReport();
        self::assertIsArray($trackedEvent);
        self::assertArrayNotHasKey('details', $trackedEvent);
        self::assertCount(1, $this->logger->contextsLoggedAt(LogLevel::WARNING));
    }

    public function testDetailsWithSlashesAndLettersOutsideAsciiAreWrittenAsTheyAre(): void
    {
        $this->startSdkServer();
        $client = $this->initializedClient();
        $client->track('page-viewed', details: ['path' => '/café/menu']);
        $client->dispose();

        $this->endARequestAfterTheFlushInterval();

        self::assertStringContainsString('"details":{"path":"/café/menu"},', $this->bodyTextOfTheOnlyReport());
    }

    public function testEachCauseOfALeftOutOrDroppedEventLogsOneWarningForTheWholeRequest(): void
    {
        $this->startSdkServer();
        $client = $this->initializedClient();

        $client->track('');
        $client->track(str_repeat('n', 151));
        $client->track('checkout-completed', value: INF);
        $client->track('checkout-completed', value: NAN);
        $client->track('checkout-completed', details: ['pro']);
        $client->track('checkout-completed', details: ['team']);
        $client->dispose();

        self::assertCount(3, $this->logger->contextsLoggedAt(LogLevel::WARNING));
    }

    public function testTheOneThousandAndFirstEventOfARequestIsNotReportedAndOneWarningSaysSo(): void
    {
        $this->startSdkServer();
        $client = $this->initializedClient();
        foreach (range(1, 1_002) as $number) {
            $client->track("event-{$number}");
        }
        $client->dispose();

        $this->endARequestAfterTheFlushInterval();

        $namesReported = array_column($this->trackedEventsOfTheOnlyReport(), 'name');
        self::assertCount(1_000, $namesReported);
        self::assertSame('event-1', $namesReported[0]);
        self::assertSame('event-1000', $namesReported[999]);
        self::assertCount(1, $this->logger->contextsLoggedAt(LogLevel::WARNING));
    }

    public function testAReportHoldsTheFirstThousandEventsTheRequestsTrackedAndTheRequestThatSendsItLogsOneWarning(): void
    {
        $this->startSdkServer();
        $firstClient = $this->initializedClient();
        foreach (range(1, 600) as $number) {
            $firstClient->track("first-request-event-{$number}");
        }
        $firstClient->dispose();
        $secondClient = $this->initializedClient();
        foreach (range(1, 600) as $number) {
            $secondClient->track("second-request-event-{$number}");
        }
        $secondClient->dispose();
        $warningsLoggedByTheRequestsThatTracked = count($this->logger->contextsLoggedAt(LogLevel::WARNING));

        $this->endARequestAfterTheFlushInterval();

        $namesReported = array_column($this->trackedEventsOfTheOnlyReport(), 'name');
        self::assertCount(1_000, $namesReported);
        self::assertSame('first-request-event-1', $namesReported[0]);
        self::assertSame('first-request-event-600', $namesReported[599]);
        self::assertSame('second-request-event-1', $namesReported[600]);
        self::assertSame('second-request-event-400', $namesReported[999]);
        self::assertSame(0, $warningsLoggedByTheRequestsThatTracked);
        self::assertCount(1, $this->logger->contextsLoggedAt(LogLevel::WARNING));
    }

    public function testAnEventTrackedForAContextWithoutAKindNamesTheUserKind(): void
    {
        $this->startSdkServer();
        $client = $this->initializedClient();
        $client->track('checkout-completed', new Context(id: 'user-42'));
        $client->dispose();

        $this->endARequestAfterTheFlushInterval();

        [$trackedEvent] = $this->trackedEventsOfTheOnlyReport();
        self::assertIsArray($trackedEvent);
        self::assertSame('user', $trackedEvent['contextKind']);
        self::assertSame('user-42', $trackedEvent['contextIdentifier']);
    }

    public function testAnEventTrackedWithoutAContextNamesNoContext(): void
    {
        $this->startSdkServer();
        $client = $this->initializedClient();
        $client->track('nightly-import-finished');
        $client->dispose();

        $this->endARequestAfterTheFlushInterval();

        [$trackedEvent] = $this->trackedEventsOfTheOnlyReport();
        self::assertIsArray($trackedEvent);
        self::assertArrayNotHasKey('contextKind', $trackedEvent);
        self::assertArrayNotHasKey('contextIdentifier', $trackedEvent);
    }

    public function testAnEventTrackedForAnAnonymousContextNamesNoContext(): void
    {
        $this->startSdkServer();
        $client = $this->initializedClient();
        $client->track('checkout-completed', new Context(id: 'visitor-9', anonymous: true));
        $client->dispose();

        $this->endARequestAfterTheFlushInterval();

        [$trackedEvent] = $this->trackedEventsOfTheOnlyReport();
        self::assertIsArray($trackedEvent);
        self::assertArrayNotHasKey('contextKind', $trackedEvent);
        self::assertArrayNotHasKey('contextIdentifier', $trackedEvent);
        self::assertStringNotContainsString('visitor-9', $this->bodyTextOfTheOnlyReport());
    }

    public function testAnEventTrackedForAContextWithoutAnIdentifierNamesNoContext(): void
    {
        $this->startSdkServer();
        $client = $this->initializedClient();
        $client->track('checkout-completed', new Context(kind: 'organization', name: 'Acme'));
        $client->dispose();

        $this->endARequestAfterTheFlushInterval();

        [$trackedEvent] = $this->trackedEventsOfTheOnlyReport();
        self::assertIsArray($trackedEvent);
        self::assertArrayNotHasKey('contextKind', $trackedEvent);
        self::assertArrayNotHasKey('contextIdentifier', $trackedEvent);
    }

    public function testAnEventTrackedForAContextWithAnEmptyIdentifierNamesNoContext(): void
    {
        $this->startSdkServer();
        $client = $this->initializedClient();
        $client->track('checkout-completed', new Context(id: ''));
        $client->dispose();

        $this->endARequestAfterTheFlushInterval();

        [$trackedEvent] = $this->trackedEventsOfTheOnlyReport();
        self::assertIsArray($trackedEvent);
        self::assertArrayNotHasKey('contextKind', $trackedEvent);
        self::assertArrayNotHasKey('contextIdentifier', $trackedEvent);
    }

    public function testAnEventTrackedForAContextWithAnInvalidKindIsReportedWithoutItAndTheKindIsCountedAndLogged(): void
    {
        $this->startSdkServer();
        $client = $this->initializedClient();
        $client->track('checkout-completed', new Context(id: 'acme', kind: 'Tenant'));
        $client->dispose();

        $this->endARequestAfterTheFlushInterval();

        [$trackedEvent] = $this->trackedEventsOfTheOnlyReport();
        self::assertIsArray($trackedEvent);
        self::assertArrayNotHasKey('contextKind', $trackedEvent);
        self::assertArrayNotHasKey('contextIdentifier', $trackedEvent);
        self::assertStringNotContainsString('acme', $this->bodyTextOfTheOnlyReport());
        self::assertSame(
            [['count' => 1, 'event' => ['reason' => 'invalid-context-kind', 'kindSample' => 'Tenant']]],
            $this->invalidContextsOfTheOnlyReport(),
        );
        self::assertSame([['kindSample' => 'Tenant']], $this->logger->contextsLoggedAt(LogLevel::ERROR));
    }

    public function testTheContextOfATrackedEventIsNotCaptured(): void
    {
        $this->startSdkServer();
        $client = $this->initializedClient();
        $client->track('checkout-completed', new Context(id: 'user-42', name: 'Ada'));
        $client->dispose();

        $this->endARequestAfterTheFlushInterval();

        $body = $this->bodyOfTheOnlyReport();
        self::assertIsArray($body['discreteEvents']);
        self::assertSame([], $body['discreteEvents']['capturedContexts']);
    }

    public function testAnEventTrackedAfterDisposeIsNotReportedAndLogsNothing(): void
    {
        $this->startSdkServer();
        $client = $this->initializedClient();
        $client->track('checkout-started');
        $client->dispose();
        $client->track('checkout-completed');
        $client->track('');

        $this->endARequestAfterTheFlushInterval();

        self::assertSame(['checkout-started'], array_column($this->trackedEventsOfTheOnlyReport(), 'name'));
        self::assertSame([], $this->logger->contextsLoggedAt(LogLevel::WARNING));
    }

    public function testAnEventTrackedByAClientThatWasNeverInitializedIsReported(): void
    {
        $this->startSdkServer();
        $client = $this->client(flushInterval: 60_000);
        $client->track('nightly-import-finished');
        $client->dispose();

        $this->endARequestAfterTheFlushInterval();

        self::assertSame(['nightly-import-finished'], array_column($this->trackedEventsOfTheOnlyReport(), 'name'));
    }

    public function testStoredTrackedEventsThatAreNotJsonObjectsAreLeftOutOfTheReport(): void
    {
        $this->startSdkServer();
        $client = $this->initializedClient();
        $client->track('checkout-completed');
        $client->dispose();
        file_put_contents(
            $this->localStoreFolder() . DIRECTORY_SEPARATOR . 'telemetry.jsonl',
            '{"collectingSince":1794484800000,"trackedEvents":["not an event","7",7,["a list"],"{\"name\":"]}' . "\n",
            FILE_APPEND,
        );

        $this->endARequestAfterTheFlushInterval();

        self::assertSame(['checkout-completed'], array_column($this->trackedEventsOfTheOnlyReport(), 'name'));
    }

    private function startSdkServer(): void
    {
        $this->server = ScriptedSdkServer::answering([
            [
                'status' => 200,
                'body' => [
                    'payloadVersion' => 1,
                    'environmentId' => 'environment-1',
                    'projectId' => 'project-1',
                    'configs' => new \stdClass(),
                    'segments' => new \stdClass(),
                    'kind' => 'full',
                    'timestamp' => '2026-11-13T12:00:00.000000Z',
                ],
            ],
            ['status' => 200],
        ]);
    }

    private function client(int $flushInterval): ConfigDirectorClient
    {
        if ($this->server === null) {
            throw new \LogicException('The test starts the SDK server before it creates a client.');
        }

        return ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            connection: new ConnectionOptions(url: $this->server->url, storagePath: $this->storagePath),
            logger: $this->logger,
            telemetry: new TelemetryOptions(flushInterval: $flushInterval),
        );
    }

    private function initializedClient(): ConfigDirectorClient
    {
        $client = $this->client(flushInterval: 60_000);
        $client->initialize();

        return $client;
    }

    private function endARequestAfterTheFlushInterval(): void
    {
        $client = $this->client(flushInterval: 1);
        $client->initialize();
        self::letTheStoredTelemetryAgePastOneMillisecond();

        $client->dispose();
    }

    private static function letTheStoredTelemetryAgePastOneMillisecond(): void
    {
        $deadline = hrtime(true) + 3_000_000;
        while (hrtime(true) < $deadline) {
            usleep(500);
        }
    }

    private function localStoreFolder(): string
    {
        return $this->storagePath . DIRECTORY_SEPARATOR . hash('sha256', 'server-sdk-key');
    }

    /**
     * @return list<array{method: string, path: string, headers: array<string, string>, body: mixed, bodyText: string}>
     */
    private function telemetryRequestsReceived(): array
    {
        return array_values(array_filter(
            $this->server?->requestsReceived() ?? [],
            static fn(array $request): bool => $request['path'] === '/server/telemetry/v1',
        ));
    }

    private function bodyTextOfTheOnlyReport(): string
    {
        $reports = $this->telemetryRequestsReceived();
        self::assertCount(1, $reports);

        return $reports[0]['bodyText'];
    }

    /**
     * @return array<array-key, mixed>
     */
    private function bodyOfTheOnlyReport(): array
    {
        $reports = $this->telemetryRequestsReceived();
        self::assertCount(1, $reports);
        self::assertIsArray($reports[0]['body']);

        return $reports[0]['body'];
    }

    /**
     * @return list<mixed>
     */
    private function trackedEventsOfTheOnlyReport(): array
    {
        $body = $this->bodyOfTheOnlyReport();
        self::assertIsArray($body['discreteEvents']);
        self::assertIsArray($body['discreteEvents']['trackedEvents']);

        return array_values($body['discreteEvents']['trackedEvents']);
    }

    /**
     * @return list<array{count: mixed, event: mixed}>
     */
    private function invalidContextsOfTheOnlyReport(): array
    {
        $body = $this->bodyOfTheOnlyReport();
        self::assertIsArray($body['aggregatedEvents']);
        self::assertIsArray($body['aggregatedEvents']['invalidContext']);

        $invalidContexts = [];
        foreach ($body['aggregatedEvents']['invalidContext'] as $entry) {
            self::assertIsArray($entry);
            $invalidContexts[] = ['count' => $entry['count'], 'event' => $entry['event']];
        }

        return $invalidContexts;
    }
}
