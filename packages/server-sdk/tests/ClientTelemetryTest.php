<?php

declare(strict_types=1);

namespace ConfigDirector\Tests;

use ConfigDirector\ConfigDirector;
use ConfigDirector\ConfigDirectorClient;
use ConfigDirector\ConnectionErrorEvent;
use ConfigDirector\ConnectionOptions;
use ConfigDirector\Context;
use ConfigDirector\Metadata;
use ConfigDirector\TelemetryOptions;
use ConfigDirector\Tests\Support\RecordingLogger;
use ConfigDirector\Tests\Support\ScriptedSdkServer;
use ConfigDirector\Tests\Support\TemporaryFolder;
use ConfigDirector\Version;
use PHPUnit\Framework\Attributes\RequiresOperatingSystem;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

/**
 * @phpstan-import-type ScriptedAnswer from ScriptedSdkServer
 */
final class ClientTelemetryTest extends TestCase
{
    private string $storagePath;

    private RecordingLogger $logger;

    private ?ScriptedSdkServer $server = null;

    private int $umaskBeforeTheTest;

    #[\Override]
    protected function setUp(): void
    {
        $this->storagePath = TemporaryFolder::create();
        $this->logger = new RecordingLogger();
        $this->umaskBeforeTheTest = umask();
    }

    #[\Override]
    protected function tearDown(): void
    {
        umask($this->umaskBeforeTheTest);
        $this->server?->stop();
        TemporaryFolder::remove($this->storagePath);
    }

    public function testAReadIsSentAsOneReportToTheTelemetryEndpoint(): void
    {
        $this->startSdkServerServing(['new-checkout' => self::booleanConfigServedByARollout()]);
        $client = $this->initializedClient();
        $client->getValue('new-checkout', false, new Context(id: 'user-42'));
        $client->dispose();

        $this->endARequestAfterTheFlushInterval(new Metadata(appName: 'storefront', appVersion: '2.1.0'));

        [$report] = $this->telemetryRequestsReceived();
        self::assertSame('POST', $report['method']);
        self::assertSame('application/json', $report['headers']['content-type']);
        self::assertSame('php-server-sdk/' . Version::CURRENT, $report['headers']['user-agent']);
        self::assertIsArray($report['body']);
        self::assertSame('server-sdk-key', $report['body']['serverSdkKey']);
        self::assertSame(
            [
                'sdkName' => 'php-server-sdk',
                'sdkVersion' => Version::CURRENT,
                'appName' => 'storefront',
                'appVersion' => '2.1.0',
            ],
            $report['body']['metaContext'],
        );
        self::assertSame(['evaluatedConfig' => 0, 'capturedContexts' => 0], $report['body']['droppedEvents']);
    }

    public function testAValueServedByAPercentageRolloutIsReportedWithItsRuleShareAndVariation(): void
    {
        $this->startSdkServerServing(['new-checkout' => self::booleanConfigServedByARollout()]);
        $client = $this->initializedClient();
        $client->getValue('new-checkout', false, new Context(id: 'user-42'));
        $client->dispose();

        $this->endARequestAfterTheFlushInterval();

        self::assertSame(
            [
                [
                    'count' => 1,
                    'event' => [
                        'contextId' => 'user-42',
                        'contextKind' => 'user',
                        'key' => 'new-checkout',
                        'type' => 'boolean',
                        'defaultValue' => ['value' => 'false'],
                        'requestedType' => 'bool',
                        'evaluatedValue' => ['value' => 'true'],
                        'evaluatedValueId' => 'value-checkout-on',
                        'usedDefault' => false,
                        'evaluationReason' => 'found-match',
                        'ruleId' => 'rule-checkout-rollout',
                        'shareId' => 'share-checkout-on',
                        'variationId' => 'variation-checkout-on',
                    ],
                ],
            ],
            $this->evaluationsOfTheOnlyReport(),
        );
    }

    public function testThreeReadsOfOneConfigForOneContextAreReportedAsOneEntryCountingThree(): void
    {
        $this->startSdkServerServing(['page-size' => self::configServingEveryContext('integer', '25', 'value-25')]);
        $client = $this->initializedClient();
        $client->getValue('page-size', 10, new Context(id: 'user-42'));
        $client->getValue('page-size', 10, new Context(id: 'user-42'));
        $client->getValue('page-size', 10, new Context(id: 'user-42'));
        $client->dispose();

        $this->endARequestAfterTheFlushInterval();

        self::assertSame(
            [
                [
                    'count' => 3,
                    'event' => [
                        'contextId' => 'user-42',
                        'contextKind' => 'user',
                        'key' => 'page-size',
                        'type' => 'integer',
                        'defaultValue' => ['value' => '10'],
                        'requestedType' => 'int',
                        'evaluatedValue' => ['value' => '25'],
                        'evaluatedValueId' => 'value-25',
                        'usedDefault' => false,
                        'evaluationReason' => 'found-match',
                    ],
                ],
            ],
            $this->evaluationsOfTheOnlyReport(),
        );
    }

    public function testReadsForDifferentContextsAreReportedAsSeparateEntries(): void
    {
        $this->startSdkServerServing(['page-size' => self::configServingEveryContext('integer', '25', 'value-25')]);
        $client = $this->initializedClient();
        $client->getValue('page-size', 10, new Context(id: 'user-42'));
        $client->getValue('page-size', 10, new Context(id: 'user-43'));
        $client->dispose();

        $this->endARequestAfterTheFlushInterval();

        $evaluations = $this->evaluationsOfTheOnlyReport();
        self::assertSame([1, 1], array_column($evaluations, 'count'));
        self::assertSame(['user-42', 'user-43'], array_column(array_column($evaluations, 'event'), 'contextId'));
    }

    public function testAReadOfAConfigThatDoesNotExistIsReportedWithItsInCodeDefaultValueAndReason(): void
    {
        $this->startSdkServerServing([]);
        $client = $this->initializedClient();
        $client->getValue('unknown-banner', 'Welcome');
        $client->dispose();

        $this->endARequestAfterTheFlushInterval();

        self::assertSame(
            [
                [
                    'count' => 1,
                    'event' => [
                        'key' => 'unknown-banner',
                        'defaultValue' => ['value' => 'Welcome'],
                        'requestedType' => 'string',
                        'evaluatedValue' => ['value' => 'Welcome'],
                        'usedDefault' => true,
                        'evaluationReason' => 'config-state-missing',
                    ],
                ],
            ],
            $this->evaluationsOfTheOnlyReport(),
        );
    }

    public function testAValueThatCannotBeReadAsTheRequestedTypeIsReportedAsTheInCodeDefaultValueWithoutItsVariation(): void
    {
        $config = self::booleanConfigServedByARollout();
        $this->startSdkServerServing(['new-checkout' => $config]);
        $client = $this->initializedClient();
        $client->getValue('new-checkout', 7, new Context(id: 'user-42'));
        $client->dispose();

        $this->endARequestAfterTheFlushInterval();

        self::assertSame(
            [
                [
                    'count' => 1,
                    'event' => [
                        'contextId' => 'user-42',
                        'contextKind' => 'user',
                        'key' => 'new-checkout',
                        'type' => 'boolean',
                        'defaultValue' => ['value' => '7'],
                        'requestedType' => 'int',
                        'evaluatedValue' => ['value' => '7'],
                        'usedDefault' => true,
                        'evaluationReason' => 'invalid-number',
                    ],
                ],
            ],
            $this->evaluationsOfTheOnlyReport(),
        );
    }

    public function testAJsonReadIsReportedByItsValueIdsWithTheRequestedTypeArray(): void
    {
        $this->startSdkServerServing([
            'theme' => self::configServingEveryContext('json', '{"color":"blue"}', 'value-theme-blue'),
        ]);
        $client = $this->initializedClient();
        $client->getValue('theme', ['fallback' => true]);
        $client->dispose();

        $this->endARequestAfterTheFlushInterval();

        self::assertSame(
            [
                [
                    'count' => 1,
                    'event' => [
                        'key' => 'theme',
                        'type' => 'json',
                        'defaultValue' => ['valueId' => '2FDZjG6cBOniOLmr9qRPI1'],
                        'requestedType' => 'array',
                        'evaluatedValue' => ['valueId' => 'value-theme-blue'],
                        'evaluatedValueId' => 'value-theme-blue',
                        'usedDefault' => false,
                        'evaluationReason' => 'found-match',
                    ],
                ],
            ],
            $this->evaluationsOfTheOnlyReport(),
        );
    }

    public function testAnArrayInCodeDefaultValueReturnedForAMissingConfigIsReportedByTheValueIdOfItsCompactJson(): void
    {
        $this->startSdkServerServing([]);
        $client = $this->initializedClient();
        $client->getValue('unknown-theme', ['greeting' => 'hello', 'sizes' => [1, 2]]);
        $client->dispose();

        $this->endARequestAfterTheFlushInterval();

        [$evaluation] = $this->evaluationsOfTheOnlyReport();
        self::assertIsArray($evaluation['event']);
        self::assertSame(['valueId' => '2HjPjshTMXoGkJcyBdeFKn'], $evaluation['event']['defaultValue']);
        self::assertSame(['valueId' => '2HjPjshTMXoGkJcyBdeFKn'], $evaluation['event']['evaluatedValue']);
        self::assertSame('array', $evaluation['event']['requestedType']);
    }

    public function testAFloatReadIsReportedAsTheNumberTextEverySdkWrites(): void
    {
        $this->startSdkServerServing(['ratio' => self::configServingEveryContext('float', '26.0', 'value-ratio')]);
        $client = $this->initializedClient();
        $client->getValue('ratio', 1.5);
        $client->dispose();

        $this->endARequestAfterTheFlushInterval();

        [$evaluation] = $this->evaluationsOfTheOnlyReport();
        self::assertIsArray($evaluation['event']);
        self::assertSame(['value' => '1.5'], $evaluation['event']['defaultValue']);
        self::assertSame(['value' => '26'], $evaluation['event']['evaluatedValue']);
        self::assertSame('float', $evaluation['event']['requestedType']);
    }

    public function testAStringOfFiveHundredCharactersIsReportedAsText(): void
    {
        $this->startSdkServerServing([]);
        $client = $this->initializedClient();
        $client->getValue('unknown-banner', str_repeat('é', 500));
        $client->dispose();

        $this->endARequestAfterTheFlushInterval();

        [$evaluation] = $this->evaluationsOfTheOnlyReport();
        self::assertIsArray($evaluation['event']);
        self::assertSame(['value' => str_repeat('é', 500)], $evaluation['event']['defaultValue']);
    }

    public function testAStringOfFiveHundredAndOneCharactersIsReportedByItsValueId(): void
    {
        $this->startSdkServerServing([]);
        $client = $this->initializedClient();
        $client->getValue('unknown-banner', str_repeat('a', 501));
        $client->dispose();

        $this->endARequestAfterTheFlushInterval();

        [$evaluation] = $this->evaluationsOfTheOnlyReport();
        self::assertIsArray($evaluation['event']);
        self::assertSame(['valueId' => '0pH2EX5cHZm26Eu000vyBv'], $evaluation['event']['defaultValue']);
        self::assertSame(['valueId' => '0pH2EX5cHZm26Eu000vyBv'], $evaluation['event']['evaluatedValue']);
    }

    public function testALongStringServedByConfigDirectorIsReportedByTheValueIdItCameWith(): void
    {
        $this->startSdkServerServing([
            'banner' => self::configServingEveryContext('string', str_repeat('a', 501), 'value-long-banner'),
        ]);
        $client = $this->initializedClient();
        $client->getValue('banner', 'Welcome');
        $client->dispose();

        $this->endARequestAfterTheFlushInterval();

        [$evaluation] = $this->evaluationsOfTheOnlyReport();
        self::assertIsArray($evaluation['event']);
        self::assertSame(['valueId' => 'value-long-banner'], $evaluation['event']['evaluatedValue']);
    }

    public function testAValueServedByARuleWithoutARolloutIsReportedWithItsVariationAndNoRule(): void
    {
        $this->startSdkServerServing(['page-size' => self::integerConfigServingFiftyToTheProPlan()]);
        $client = $this->initializedClient();
        $client->getValue('page-size', 10, new Context(id: 'user-42', traits: ['plan' => 'pro']));
        $client->dispose();

        $this->endARequestAfterTheFlushInterval();

        self::assertSame(
            [
                [
                    'count' => 1,
                    'event' => [
                        'contextId' => 'user-42',
                        'contextKind' => 'user',
                        'key' => 'page-size',
                        'type' => 'integer',
                        'defaultValue' => ['value' => '10'],
                        'requestedType' => 'int',
                        'evaluatedValue' => ['value' => '50'],
                        'evaluatedValueId' => 'value-50',
                        'usedDefault' => false,
                        'evaluationReason' => 'found-match',
                        'variationId' => 'variation-50',
                    ],
                ],
            ],
            $this->evaluationsOfTheOnlyReport(),
        );
    }

    public function testEachWatchDeliveryIsReportedAsAnEvaluation(): void
    {
        $this->startSdkServerServing(['page-size' => self::configServingEveryContext('integer', '25', 'value-25')]);
        $client = $this->client(flushInterval: 60_000);
        $client->watch('page-size', 10, static function (): void {});
        $client->initialize();
        $client->dispose();

        $this->endARequestAfterTheFlushInterval();

        $evaluations = $this->evaluationsOfTheOnlyReport();
        self::assertSame([1], array_column($evaluations, 'count'));
        self::assertSame(['page-size'], array_column(array_column($evaluations, 'event'), 'key'));
    }

    public function testGetAllConfigsIsNotReported(): void
    {
        $this->startSdkServerServing(['page-size' => self::configServingEveryContext('integer', '25', 'value-25')]);
        $client = $this->initializedClient();
        $client->getAllConfigs(new Context(id: 'user-42'));
        $client->dispose();

        $this->endARequestAfterTheFlushInterval();

        self::assertSame([], $this->telemetryRequestsReceived());
    }

    public function testAReadBeforeInitializeIsReportedAsClientNotReady(): void
    {
        $this->startSdkServerServing([]);
        $client = $this->client(flushInterval: 60_000);
        $client->getValue('page-size', 10);
        $client->dispose();

        $this->endARequestAfterTheFlushInterval();

        [$evaluation] = $this->evaluationsOfTheOnlyReport();
        self::assertIsArray($evaluation['event']);
        self::assertSame('client-not-ready', $evaluation['event']['evaluationReason']);
        self::assertTrue($evaluation['event']['usedDefault']);
    }

    public function testAReadAfterDisposeIsNotReported(): void
    {
        $this->startSdkServerServing(['page-size' => self::configServingEveryContext('integer', '25', 'value-25')]);
        $client = $this->initializedClient();
        $client->getValue('page-size', 10);
        $client->dispose();
        $client->getValue('page-size', 10);
        $client->getValue('page-size', 10);
        $client->dispose();

        $this->endARequestAfterTheFlushInterval();

        self::assertSame([1], array_column($this->evaluationsOfTheOnlyReport(), 'count'));
    }

    public function testEveryEntryOfAReportCarriesWhenItsCollectingStartedAndWhenItWasSent(): void
    {
        $this->startSdkServerServing([]);
        $startedAt = new \DateTimeImmutable();
        $client = $this->initializedClient();
        $client->getValue('unknown-banner', 'Welcome');
        $client->dispose();

        $this->endARequestAfterTheFlushInterval();

        $body = $this->bodyOfTheOnlyReport();
        self::assertIsArray($body['aggregatedEvents']);
        self::assertIsArray($body['aggregatedEvents']['evaluatedConfig']);
        self::assertIsArray($body['aggregatedEvents']['evaluatedConfig'][0]);
        $entry = $body['aggregatedEvents']['evaluatedConfig'][0];
        self::assertIsString($entry['startTime']);
        self::assertIsString($entry['endTime']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $entry['startTime']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $entry['endTime']);
        $startTime = new \DateTimeImmutable($entry['startTime']);
        $endTime = new \DateTimeImmutable($entry['endTime']);
        self::assertGreaterThanOrEqual($startedAt->modify('-1 second'), $startTime);
        self::assertGreaterThanOrEqual($startTime, $endTime);
        self::assertLessThanOrEqual(new \DateTimeImmutable(), $endTime);
    }

    public function testAnIdentifiedContextIsCapturedOnceWithItsKindNameAndTraits(): void
    {
        $this->startSdkServerServing([]);
        $client = $this->initializedClient();
        $context = new Context(id: 'user-42', name: 'Ada', traits: ['plan' => 'pro', 'seats' => 5]);
        $client->getValue('unknown-banner', 'Welcome', $context);
        $client->getValue('unknown-page-size', 10, $context);
        $client->dispose();

        $this->endARequestAfterTheFlushInterval();

        self::assertSame(
            [
                [
                    'id' => 'user-42',
                    'kind' => 'user',
                    'name' => 'Ada',
                    'traits' => ['plan' => 'pro', 'seats' => 5],
                    'anonymous' => false,
                ],
            ],
            $this->capturedContextsOfTheOnlyReport(),
        );
    }

    public function testAContextOfACustomKindIsCapturedAndNamedOnItsEvaluationUnderThatKind(): void
    {
        $this->startSdkServerServing([]);
        $client = $this->initializedClient();
        $client->getValue('unknown-banner', 'Welcome', new Context(id: 'device-7', kind: 'device'));
        $client->dispose();

        $this->endARequestAfterTheFlushInterval();

        self::assertSame(
            [['id' => 'device-7', 'kind' => 'device', 'anonymous' => false]],
            $this->capturedContextsOfTheOnlyReport(),
        );
        [$evaluation] = $this->evaluationsOfTheOnlyReport();
        self::assertIsArray($evaluation['event']);
        self::assertSame('device-7', $evaluation['event']['contextId']);
        self::assertSame('device', $evaluation['event']['contextKind']);
    }

    public function testTwoContextsOfDifferentKindsWithTheSameIdentifierAreBothCaptured(): void
    {
        $this->startSdkServerServing([]);
        $client = $this->initializedClient();
        $client->getValue('unknown-banner', 'Welcome', new Context(id: '7', kind: 'device'));
        $client->getValue('unknown-banner', 'Welcome', new Context(id: '7', kind: 'tenant'));
        $client->dispose();

        $this->endARequestAfterTheFlushInterval();

        self::assertSame(
            [
                ['id' => '7', 'kind' => 'device', 'anonymous' => false],
                ['id' => '7', 'kind' => 'tenant', 'anonymous' => false],
            ],
            $this->capturedContextsOfTheOnlyReport(),
        );
    }

    public function testAnAnonymousContextIsNeverCapturedAndItsEvaluationNamesNoContext(): void
    {
        $this->startSdkServerServing([]);
        $client = $this->initializedClient();
        $client->getValue('unknown-banner', 'Welcome', new Context(id: 'visitor-9', name: 'Visitor', anonymous: true));
        $client->dispose();

        $this->endARequestAfterTheFlushInterval();

        self::assertSame([], $this->capturedContextsOfTheOnlyReport());
        [$evaluation] = $this->evaluationsOfTheOnlyReport();
        self::assertIsArray($evaluation['event']);
        self::assertArrayNotHasKey('contextId', $evaluation['event']);
        self::assertArrayNotHasKey('contextKind', $evaluation['event']);
        self::assertStringNotContainsString('visitor-9', json_encode($this->bodyOfTheOnlyReport(), JSON_THROW_ON_ERROR));
    }

    public function testAContextWithoutAnIdentifierIsNotCapturedAndItsEvaluationNamesNoContext(): void
    {
        $this->startSdkServerServing([]);
        $client = $this->initializedClient();
        $client->getValue('unknown-banner', 'Welcome', new Context(kind: 'device', traits: ['os' => 'linux']));
        $client->dispose();

        $this->endARequestAfterTheFlushInterval();

        self::assertSame([], $this->capturedContextsOfTheOnlyReport());
        [$evaluation] = $this->evaluationsOfTheOnlyReport();
        self::assertIsArray($evaluation['event']);
        self::assertArrayNotHasKey('contextId', $evaluation['event']);
        self::assertArrayNotHasKey('contextKind', $evaluation['event']);
    }

    public function testAContextWithAnInvalidKindIsCountedWithItsKindSampleAndNothingElseOfIt(): void
    {
        $this->startSdkServerServing([]);
        $client = $this->initializedClient();
        $context = new Context(
            id: 'device-7',
            kind: 'Device',
            name: 'Kitchen tablet',
            traits: ['owner' => 'ada@example.com'],
        );
        $client->getValue('unknown-banner', 'Welcome', $context);
        $client->getValue('unknown-banner', 'Welcome', $context);
        $client->dispose();

        $this->endARequestAfterTheFlushInterval();

        self::assertSame(
            [['count' => 2, 'event' => ['reason' => 'invalid-context-kind', 'kindSample' => 'Device']]],
            $this->invalidContextsOfTheOnlyReport(),
        );
        self::assertSame([], $this->capturedContextsOfTheOnlyReport());
        $reportJson = json_encode($this->bodyOfTheOnlyReport(), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('device-7', $reportJson);
        self::assertStringNotContainsString('Kitchen tablet', $reportJson);
        self::assertStringNotContainsString('ada@example.com', $reportJson);
    }

    public function testInvalidKindsBeyondTwentyDifferentOnesAreCountedTogetherWithoutAKindSample(): void
    {
        $this->startSdkServerServing([]);
        $client = $this->initializedClient();
        foreach (range(1, 20) as $number) {
            $client->getValue('unknown-banner', 'Welcome', new Context(kind: 'Kind' . $number));
        }
        $client->getValue('unknown-banner', 'Welcome', new Context(kind: 'Kind21'));
        $client->getValue('unknown-banner', 'Welcome', new Context(kind: 'Kind22'));
        $client->dispose();

        $this->endARequestAfterTheFlushInterval();

        $invalidContexts = $this->invalidContextsOfTheOnlyReport();
        self::assertCount(21, $invalidContexts);
        self::assertSame(
            ['count' => 1, 'event' => ['reason' => 'invalid-context-kind', 'kindSample' => 'Kind20']],
            $invalidContexts[19],
        );
        self::assertSame(['count' => 2, 'event' => ['reason' => 'invalid-context-kind']], $invalidContexts[20]);
    }

    public function testSeventyPercentOfTheEventQueueLimitIsKeptForEvaluationsAndTheOldestOverItAreReportedAsDropped(): void
    {
        $this->startSdkServerServing([]);
        $client = $this->initializedClient(eventQueueLimit: 100);
        $client->getValue('unknown-flag-1', false);
        $client->getValue('unknown-flag-1', false);
        foreach (range(2, 72) as $number) {
            $client->getValue("unknown-flag-{$number}", false);
        }
        $client->dispose();

        $this->endARequestAfterTheFlushInterval();

        $keysReported = array_column(array_column($this->evaluationsOfTheOnlyReport(), 'event'), 'key');
        self::assertCount(70, $keysReported);
        self::assertSame('unknown-flag-3', $keysReported[0]);
        self::assertSame('unknown-flag-72', $keysReported[69]);
        self::assertSame(
            ['evaluatedConfig' => 3, 'capturedContexts' => 0],
            $this->bodyOfTheOnlyReport()['droppedEvents'],
        );
    }

    public function testTheEvaluationShareOfTheEventQueueLimitIsRoundedUp(): void
    {
        $this->startSdkServerServing([]);
        $client = $this->initializedClient(eventQueueLimit: 101);
        foreach (range(1, 72) as $number) {
            $client->getValue("unknown-flag-{$number}", false);
        }
        $client->dispose();

        $this->endARequestAfterTheFlushInterval();

        self::assertCount(71, $this->evaluationsOfTheOnlyReport());
        self::assertSame(
            ['evaluatedConfig' => 1, 'capturedContexts' => 0],
            $this->bodyOfTheOnlyReport()['droppedEvents'],
        );
    }

    public function testThirtyPercentOfTheEventQueueLimitIsKeptForContextsAndTheOldestOverItAreReportedAsDropped(): void
    {
        $this->startSdkServerServing([]);
        $client = $this->initializedClient(eventQueueLimit: 100);
        foreach (range(1, 31) as $number) {
            $client->getValue('unknown-flag', false, new Context(id: "user-{$number}"));
        }
        $client->dispose();

        $this->endARequestAfterTheFlushInterval();

        $identifiersCaptured = array_column($this->capturedContextsOfTheOnlyReport(), 'id');
        self::assertCount(30, $identifiersCaptured);
        self::assertSame('user-2', $identifiersCaptured[0]);
        self::assertSame('user-31', $identifiersCaptured[29]);
        self::assertSame(
            ['evaluatedConfig' => 0, 'capturedContexts' => 1],
            $this->bodyOfTheOnlyReport()['droppedEvents'],
        );
    }

    public function testTheContextShareOfTheEventQueueLimitIsRoundedDown(): void
    {
        $this->startSdkServerServing([]);
        $client = $this->initializedClient(eventQueueLimit: 109);
        foreach (range(1, 33) as $number) {
            $client->getValue('unknown-flag', false, new Context(id: "user-{$number}"));
        }
        $client->dispose();

        $this->endARequestAfterTheFlushInterval();

        self::assertCount(32, $this->capturedContextsOfTheOnlyReport());
        self::assertSame(
            ['evaluatedConfig' => 0, 'capturedContexts' => 1],
            $this->bodyOfTheOnlyReport()['droppedEvents'],
        );
    }

    public function testAReportOfSeveralClientsHoldsNoMoreThanTheEventQueueLimitAndReportsTheRestAsDropped(): void
    {
        $this->startSdkServerServing([]);
        $firstClient = $this->initializedClient();
        foreach (range(1, 40) as $number) {
            $firstClient->getValue("unknown-flag-{$number}", false);
        }
        $firstClient->dispose();
        $secondClient = $this->initializedClient();
        foreach (range(41, 80) as $number) {
            $secondClient->getValue("unknown-flag-{$number}", false);
        }
        $secondClient->dispose();

        $this->endARequestAfterTheFlushInterval(eventQueueLimit: 100);

        $keysReported = array_column(array_column($this->evaluationsOfTheOnlyReport(), 'event'), 'key');
        self::assertCount(70, $keysReported);
        self::assertSame('unknown-flag-11', $keysReported[0]);
        self::assertSame('unknown-flag-80', $keysReported[69]);
        self::assertSame(
            ['evaluatedConfig' => 10, 'capturedContexts' => 0],
            $this->bodyOfTheOnlyReport()['droppedEvents'],
        );
    }

    public function testTelemetryThatWouldTakeTheStoredTelemetryPastEightMebibytesIsReportedAsDropped(): void
    {
        $this->startSdkServerServing([]);
        foreach (range(1, 8) as $number) {
            $client = $this->initializedClient();
            $client->getValue(
                'unknown-flag',
                false,
                new Context(id: "user-{$number}", traits: ['biography' => str_repeat('a', 1_048_576)]),
            );
            $client->dispose();
        }
        $clientWhoseTelemetryStillFits = $this->initializedClient();
        $clientWhoseTelemetryStillFits->getValue('unknown-flag', false, new Context(id: 'user-9'));
        $clientWhoseTelemetryStillFits->dispose();

        $this->endARequestAfterTheFlushInterval();

        self::assertSame(
            ['user-1', 'user-2', 'user-3', 'user-4', 'user-5', 'user-6', 'user-7', 'user-9'],
            array_column($this->capturedContextsOfTheOnlyReport(), 'id'),
        );
        self::assertSame(
            ['evaluatedConfig' => 1, 'capturedContexts' => 1],
            $this->bodyOfTheOnlyReport()['droppedEvents'],
        );
    }

    public function testAnAcceptedReportLogsNoWarning(): void
    {
        $this->startSdkServerServing([]);
        $client = $this->initializedClient();
        $client->getValue('unknown-flag', false);
        $client->dispose();

        $this->endARequestAfterTheFlushInterval();

        self::assertCount(1, $this->telemetryRequestsReceived());
        self::assertSame([], $this->logger->contextsLoggedAt(LogLevel::WARNING));
        self::assertSame([], $this->logger->contextsLoggedAt(LogLevel::ERROR));
    }

    public function testAReportRefusedWithA401StopsTheTelemetryOfTheRequestsThatFollow(): void
    {
        $this->startSdkServerServing([], [['status' => 401], ['status' => 200]]);
        $client = $this->initializedClient();
        $client->getValue('unknown-flag', false);
        $client->dispose();
        $this->endARequestAfterTheFlushInterval();

        $laterClient = $this->initializedClient();
        $laterClient->getValue('unknown-flag', false);
        $laterClient->dispose();
        $this->endARequestAfterTheFlushInterval();

        self::assertCount(1, $this->telemetryRequestsReceived());
        self::assertSame([['status' => 401]], $this->logger->contextsLoggedAt(LogLevel::WARNING));
        self::assertSame([], $this->logger->contextsLoggedAt(LogLevel::ERROR));
    }

    public function testARefusedReportFiresNoConnectionError(): void
    {
        $this->startSdkServerServing([], [['status' => 401]]);
        $client = $this->initializedClient();
        $client->getValue('unknown-flag', false);
        $client->dispose();
        $clientThatSends = $this->client(flushInterval: 1);
        $connectionErrors = [];
        $clientThatSends->on('connectionError', static function (ConnectionErrorEvent $event) use (&$connectionErrors): void {
            $connectionErrors[] = $event;
        });
        $clientThatSends->initialize();
        self::letTheStoredTelemetryAgePastOneMillisecond();

        $clientThatSends->dispose();

        self::assertCount(1, $this->telemetryRequestsReceived());
        self::assertSame([], $connectionErrors);
    }

    public function testAReportRefusedWithA413IsDiscardedAndTheNextReportCountsWhatItHeldAsDropped(): void
    {
        $this->startSdkServerServing([], [['status' => 413], ['status' => 200]]);
        $client = $this->initializedClient();
        $client->getValue('unknown-flag', false, new Context(id: 'user-42'));
        $client->getValue('unknown-flag', false, new Context(id: 'user-42'));
        $client->dispose();
        $this->endARequestAfterTheFlushInterval();

        $laterClient = $this->initializedClient();
        $laterClient->getValue('unknown-banner', 'Welcome');
        $laterClient->dispose();
        $this->endARequestAfterTheFlushInterval();

        [, $laterReport] = $this->telemetryRequestsReceived();
        self::assertIsArray($laterReport['body']);
        self::assertIsArray($laterReport['body']['aggregatedEvents']);
        self::assertIsArray($laterReport['body']['aggregatedEvents']['evaluatedConfig']);
        self::assertSame(
            ['unknown-banner'],
            array_column(array_column($laterReport['body']['aggregatedEvents']['evaluatedConfig'], 'event'), 'key'),
        );
        self::assertSame(['evaluatedConfig' => 2, 'capturedContexts' => 1], $laterReport['body']['droppedEvents']);
        self::assertSame([['status' => 413]], $this->logger->contextsLoggedAt(LogLevel::WARNING));
    }

    public function testAReportAnsweredWithA500IsDiscardedAndTheNextReportCountsWhatItHeldAsDropped(): void
    {
        $this->startSdkServerServing([], [['status' => 500], ['status' => 200]]);
        $client = $this->initializedClient();
        $client->getValue('unknown-flag', false);
        $client->dispose();
        $this->endARequestAfterTheFlushInterval();

        $laterClient = $this->initializedClient();
        $laterClient->getValue('unknown-banner', 'Welcome');
        $laterClient->dispose();
        $this->endARequestAfterTheFlushInterval();

        [, $laterReport] = $this->telemetryRequestsReceived();
        self::assertIsArray($laterReport['body']);
        self::assertSame(['evaluatedConfig' => 1, 'capturedContexts' => 0], $laterReport['body']['droppedEvents']);
        self::assertSame([['status' => 500]], $this->logger->contextsLoggedAt(LogLevel::WARNING));
    }

    public function testAReportAnsweredWithA429IsDiscardedAndTheNextReportIsSent(): void
    {
        $this->startSdkServerServing([], [['status' => 429], ['status' => 200]]);
        $client = $this->initializedClient();
        $client->getValue('unknown-flag', false);
        $client->dispose();
        $this->endARequestAfterTheFlushInterval();

        $laterClient = $this->initializedClient();
        $laterClient->getValue('unknown-banner', 'Welcome');
        $laterClient->dispose();
        $this->endARequestAfterTheFlushInterval();

        self::assertCount(2, $this->telemetryRequestsReceived());
        self::assertSame([['status' => 429]], $this->logger->contextsLoggedAt(LogLevel::WARNING));
    }

    public function testAReportThatGetsNoAnswerIsDroppedAndTheNextReportCountsWhatItHeldAsDropped(): void
    {
        $this->startSdkServerServing([], [['closes' => 'before-answering'], ['status' => 200]]);
        $client = $this->initializedClient();
        $client->getValue('unknown-flag', false, new Context(id: 'user-42'));
        $client->dispose();
        $this->endARequestAfterTheFlushInterval();

        $laterClient = $this->initializedClient();
        $laterClient->getValue('unknown-banner', 'Welcome');
        $laterClient->dispose();
        $this->endARequestAfterTheFlushInterval();

        [, $laterReport] = $this->telemetryRequestsReceived();
        self::assertIsArray($laterReport['body']);
        self::assertSame(['evaluatedConfig' => 1, 'capturedContexts' => 1], $laterReport['body']['droppedEvents']);
        $warnings = $this->logger->contextsLoggedAt(LogLevel::WARNING);
        self::assertCount(1, $warnings);
        self::assertIsString($warnings[0]['error']);
        self::assertNotSame('', $warnings[0]['error']);
    }

    public function testAReportWaitsFiveSecondsForItsAnswer(): void
    {
        $this->startSdkServerServing([], [['status' => 200, 'delayMilliseconds' => 7_000]]);
        $client = $this->initializedClient();
        $client->getValue('unknown-flag', false);
        $client->dispose();
        $clientThatSends = $this->client(flushInterval: 1);
        $clientThatSends->initialize();
        self::letTheStoredTelemetryAgePastOneMillisecond();

        $startedAt = hrtime(true);
        $clientThatSends->dispose();
        $millisecondsWaited = intdiv(hrtime(true) - $startedAt, 1_000_000);

        self::assertGreaterThanOrEqual(4_900, $millisecondsWaited);
        self::assertLessThan(6_500, $millisecondsWaited);
        self::assertCount(1, $this->logger->contextsLoggedAt(LogLevel::WARNING));
    }

    public function testTelemetryThatCannotBeWrittenToItsFileLogsOneWarningForEachClientAndIsCountedAsDropped(): void
    {
        $this->startSdkServerServing([]);
        $firstClient = $this->initializedClient();
        $firstClient->getValue('unknown-flag', false);
        $secondClient = $this->initializedClient();
        $secondClient->getValue('unknown-flag', false, new Context(id: 'user-42'));
        $telemetryFile = $this->localStoreFolder() . DIRECTORY_SEPARATOR . 'telemetry.jsonl';
        mkdir($telemetryFile);
        $firstClient->dispose();
        $secondClient->dispose();
        rmdir($telemetryFile);

        $this->endARequestAfterTheFlushInterval();

        self::assertSame(
            [['file' => $telemetryFile], ['file' => $telemetryFile]],
            $this->logger->contextsLoggedAt(LogLevel::WARNING),
        );
        self::assertSame(
            ['evaluatedConfig' => 2, 'capturedContexts' => 1],
            $this->bodyOfTheOnlyReport()['droppedEvents'],
        );
    }

    public function testTelemetryWhoseLockFileCannotBeUsedLogsOneWarningNamingTheFile(): void
    {
        $this->startSdkServerServing([]);
        $client = $this->initializedClient();
        $client->getValue('unknown-flag', false);
        $lockFile = $this->localStoreFolder() . DIRECTORY_SEPARATOR . 'telemetry.lock';
        mkdir($lockFile);

        $client->dispose();

        self::assertSame([['file' => $lockFile]], $this->logger->contextsLoggedAt(LogLevel::WARNING));
    }

    public function testAClientThatWasNeverInitializedAndReadNothingCreatesNoLocalStore(): void
    {
        $this->startSdkServerServing([]);
        $client = $this->client(flushInterval: 1);
        self::letTheStoredTelemetryAgePastOneMillisecond();

        $client->dispose();

        self::assertSame([], TemporaryFolder::entriesOf($this->storagePath));
        self::assertSame([], $this->server?->requestsReceived());
    }

    public function testTelemetryStoredAfterALineThatAKilledProcessLeftUnfinishedIsStillReported(): void
    {
        $this->startSdkServerServing([]);
        $client = $this->initializedClient();
        $client->getValue('unknown-flag', false);
        $client->dispose();
        file_put_contents(
            $this->localStoreFolder() . DIRECTORY_SEPARATOR . 'telemetry.jsonl',
            '{"collectingSince":1794484800000,"evaluationCounts":{"{\\"key\\":\\"unfin',
            FILE_APPEND,
        );
        $laterClient = $this->initializedClient();
        $laterClient->getValue('unknown-banner', 'Welcome');
        $laterClient->dispose();

        $this->endARequestAfterTheFlushInterval();

        self::assertSame(
            ['unknown-flag', 'unknown-banner'],
            array_column(array_column($this->evaluationsOfTheOnlyReport(), 'event'), 'key'),
        );
    }

    public function testEntriesOfTheStoredTelemetryThatAreNotJsonAreLeftOutOfTheReport(): void
    {
        $this->startSdkServerServing([]);
        $client = $this->initializedClient();
        $client->getValue('unknown-flag', false);
        $client->dispose();
        file_put_contents(
            $this->localStoreFolder() . DIRECTORY_SEPARATOR . 'telemetry.jsonl',
            '{"collectingSince":1794484800000,"evaluationCounts":{"not an event":4},'
            . '"capturedContexts":{"user:user-42":"not a context"}}' . "\n",
            FILE_APPEND,
        );

        $this->endARequestAfterTheFlushInterval();

        self::assertSame(
            ['unknown-flag'],
            array_column(array_column($this->evaluationsOfTheOnlyReport(), 'event'), 'key'),
        );
        self::assertSame([], $this->capturedContextsOfTheOnlyReport());
    }

    public function testAReadForAContextThatCannotBeWrittenAsJsonReturnsItsValueAndIsNotReported(): void
    {
        $this->startSdkServerServing(['page-size' => self::configServingEveryContext('integer', '25', 'value-25')]);
        $client = $this->initializedClient();
        $traitThatCannotBeWrittenAsJson = new class implements \JsonSerializable {
            #[\Override]
            public function jsonSerialize(): never
            {
                throw new \RuntimeException('This trait cannot be written as JSON.');
            }
        };
        $pageSize = $client->getValue(
            'page-size',
            10,
            new Context(id: 'user-42', traits: ['session' => $traitThatCannotBeWrittenAsJson]),
        );
        $client->getValue('unknown-banner', 'Welcome');
        $client->dispose();

        $this->endARequestAfterTheFlushInterval();

        self::assertSame(25, $pageSize);
        self::assertSame(
            ['unknown-banner'],
            array_column(array_column($this->evaluationsOfTheOnlyReport(), 'event'), 'key'),
        );
        self::assertSame([], $this->capturedContextsOfTheOnlyReport());
    }

    public function testAContextWithAnInvalidKindGivenToGetAllConfigsIsCounted(): void
    {
        $this->startSdkServerServing([]);
        $client = $this->initializedClient();
        $client->getAllConfigs(new Context(id: 'device-7', kind: 'Device'));
        $client->dispose();

        $this->endARequestAfterTheFlushInterval();

        self::assertSame(
            [['count' => 1, 'event' => ['reason' => 'invalid-context-kind', 'kindSample' => 'Device']]],
            $this->invalidContextsOfTheOnlyReport(),
        );
        self::assertSame([], $this->evaluationsOfTheOnlyReport());
    }

    public function testAContextWithAnEmptyIdentifierIsNotCapturedAndItsEvaluationNamesNoContext(): void
    {
        $this->startSdkServerServing([]);
        $client = $this->initializedClient();
        $client->getValue('unknown-banner', 'Welcome', new Context(id: '', name: 'Nobody'));
        $client->dispose();

        $this->endARequestAfterTheFlushInterval();

        self::assertSame([], $this->capturedContextsOfTheOnlyReport());
        [$evaluation] = $this->evaluationsOfTheOnlyReport();
        self::assertIsArray($evaluation['event']);
        self::assertArrayNotHasKey('contextId', $evaluation['event']);
        self::assertArrayNotHasKey('contextKind', $evaluation['event']);
    }

    public function testAnInCodeDefaultValueThatIsNotUtf8IsReportedWithItsUnreadableBytesReplaced(): void
    {
        $this->startSdkServerServing([]);
        $client = $this->initializedClient();
        $client->getValue('unknown-banner', "Caf\xE9");
        $client->dispose();

        $this->endARequestAfterTheFlushInterval();

        [$evaluation] = $this->evaluationsOfTheOnlyReport();
        self::assertIsArray($evaluation['event']);
        self::assertSame(['value' => "Caf\u{FFFD}"], $evaluation['event']['defaultValue']);
    }

    public function testABaseUrlThatEndsWithASlashReachesTheSameTelemetryEndpoint(): void
    {
        $this->startSdkServerServing([]);
        $client = ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            connection: new ConnectionOptions(url: $this->server?->url . '/', storagePath: $this->storagePath),
            logger: $this->logger,
            telemetry: new TelemetryOptions(flushInterval: 1),
        );
        $client->initialize();
        self::letTheStoredTelemetryAgePastOneMillisecond();
        $client->getValue('unknown-flag', false);

        $client->dispose();

        self::assertSame(
            ['/server/polling/v1', '/server/telemetry/v1'],
            array_column($this->server?->requestsReceived() ?? [], 'path'),
        );
    }

    public function testAReportAnsweredWithA204IsAccepted(): void
    {
        $this->startSdkServerServing([], [['status' => 204], ['status' => 200]]);
        $client = $this->initializedClient();
        $client->getValue('unknown-flag', false);
        $client->dispose();
        $this->endARequestAfterTheFlushInterval();

        $laterClient = $this->initializedClient();
        $laterClient->getValue('unknown-banner', 'Welcome');
        $laterClient->dispose();
        $this->endARequestAfterTheFlushInterval();

        [, $laterReport] = $this->telemetryRequestsReceived();
        self::assertIsArray($laterReport['body']);
        self::assertSame(['evaluatedConfig' => 0, 'capturedContexts' => 0], $laterReport['body']['droppedEvents']);
        self::assertSame([], $this->logger->contextsLoggedAt(LogLevel::WARNING));
    }

    public function testAReportRefusedWithA400StopsTheTelemetryOfTheRequestsThatFollow(): void
    {
        $this->startSdkServerServing([], [['status' => 400], ['status' => 200]]);
        $client = $this->initializedClient();
        $client->getValue('unknown-flag', false);
        $client->dispose();
        $this->endARequestAfterTheFlushInterval();

        $laterClient = $this->initializedClient();
        $laterClient->getValue('unknown-flag', false);
        $laterClient->dispose();
        $this->endARequestAfterTheFlushInterval();

        self::assertCount(1, $this->telemetryRequestsReceived());
        self::assertSame([['status' => 400]], $this->logger->contextsLoggedAt(LogLevel::WARNING));
    }

    public function testARedirectIsNotFollowedAndItsReportIsDiscarded(): void
    {
        $this->startSdkServerServing(
            [],
            [['status' => 302, 'headers' => ['Location' => '/server/telemetry/v1']], ['status' => 200]],
        );
        $client = $this->initializedClient();
        $client->getValue('unknown-flag', false);
        $client->dispose();

        $this->endARequestAfterTheFlushInterval();

        self::assertCount(1, $this->telemetryRequestsReceived());
        self::assertSame([['status' => 302]], $this->logger->contextsLoggedAt(LogLevel::WARNING));
    }

    public function testWhatTwoFailedReportsHeldIsCountedAsDroppedByTheNextReport(): void
    {
        $this->startSdkServerServing([], [['status' => 500], ['status' => 500], ['status' => 200]]);
        foreach (['unknown-flag', 'unknown-banner', 'unknown-theme'] as $configKey) {
            $client = $this->initializedClient();
            $client->getValue($configKey, false, new Context(id: "user-reading-{$configKey}"));
            $client->dispose();
            $this->endARequestAfterTheFlushInterval();
        }

        [, , $thirdReport] = $this->telemetryRequestsReceived();
        self::assertIsArray($thirdReport['body']);
        self::assertSame(['evaluatedConfig' => 2, 'capturedContexts' => 2], $thirdReport['body']['droppedEvents']);
    }

    public function testWhatAFailedReportHeldIsCountedAsDroppedOnce(): void
    {
        $this->startSdkServerServing([], [['status' => 500], ['status' => 200], ['status' => 200]]);
        foreach (['unknown-flag', 'unknown-banner', 'unknown-theme'] as $configKey) {
            $client = $this->initializedClient();
            $client->getValue($configKey, false);
            $client->dispose();
            $this->endARequestAfterTheFlushInterval();
        }

        [, , $thirdReport] = $this->telemetryRequestsReceived();
        self::assertIsArray($thirdReport['body']);
        self::assertSame(['evaluatedConfig' => 0, 'capturedContexts' => 0], $thirdReport['body']['droppedEvents']);
    }

    #[RequiresOperatingSystem('Linux|Darwin')]
    public function testEveryTelemetryFileOfTheLocalStoreIsReadableByItsOwnerOnly(): void
    {
        umask(0);
        $this->startSdkServerServing([]);
        $client = $this->initializedClient();
        $client->getValue('unknown-flag', false);

        $client->dispose();

        $modes = [];
        foreach (TemporaryFolder::entriesOf($this->localStoreFolder()) as $fileName) {
            $modes[$fileName] = fileperms($this->localStoreFolder() . '/' . $fileName) & 0o777;
        }
        self::assertSame(0o600, $modes['telemetry.jsonl']);
        self::assertSame(0o600, $modes['telemetry.lock']);
        self::assertSame(0o600, $modes['telemetry-tally.json']);
    }

    public function testAClientWhoseLocalStoreCannotBeUsedSendsNoTelemetry(): void
    {
        $this->startSdkServerServing([]);
        $fileInTheWayOfTheLocalStore = $this->storagePath . DIRECTORY_SEPARATOR . 'a-file';
        touch($fileInTheWayOfTheLocalStore);
        $client = ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            connection: new ConnectionOptions(
                url: $this->server->url ?? '',
                storagePath: $fileInTheWayOfTheLocalStore . DIRECTORY_SEPARATOR . 'local-stores',
            ),
            logger: $this->logger,
            telemetry: new TelemetryOptions(flushInterval: 1),
        );
        $client->initialize();
        self::letTheStoredTelemetryAgePastOneMillisecond();
        $client->getValue('unknown-flag', false);

        $client->dispose();

        self::assertSame([], $this->server?->requestsReceived());
        self::assertSame([], $this->logger->contextsLoggedAt(LogLevel::WARNING));
    }

    public function testAJsonConfigReadAsAStringIsReportedByItsValueIds(): void
    {
        $this->startSdkServerServing([
            'theme' => self::configServingEveryContext('json', '{"color":"blue"}', 'value-theme-blue'),
        ]);
        $client = $this->initializedClient();
        $client->getValue('theme', 'fallback');
        $client->dispose();

        $this->endARequestAfterTheFlushInterval();

        [$evaluation] = $this->evaluationsOfTheOnlyReport();
        self::assertIsArray($evaluation['event']);
        self::assertSame(['valueId' => '0DNWcqYZCP0V1fCd5DKy69'], $evaluation['event']['defaultValue']);
        self::assertSame(['valueId' => 'value-theme-blue'], $evaluation['event']['evaluatedValue']);
        self::assertSame('string', $evaluation['event']['requestedType']);
    }

    public function testStoredTelemetryThatHoldsOnlyACapturedContextIsReported(): void
    {
        $this->startSdkServerServing([]);
        $client = $this->initializedClient();
        $client->dispose();
        file_put_contents(
            $this->localStoreFolder() . DIRECTORY_SEPARATOR . 'telemetry.jsonl',
            '{"collectingSince":1794484800000,"capturedContexts":{"user:user-42":"{\\"id\\":\\"user-42\\"}"}}' . "\n",
        );
        file_put_contents(
            $this->localStoreFolder() . DIRECTORY_SEPARATOR . 'telemetry-tally.json',
            '{"collectingSince":1794484800000,"droppedEvaluations":0,"droppedCapturedContexts":0}',
        );

        $this->endARequestAfterTheFlushInterval();

        self::assertSame([['id' => 'user-42']], $this->capturedContextsOfTheOnlyReport());
    }

    public function testALargeReportIsSentAtOnceWithoutAskingTheServerWhetherToContinue(): void
    {
        $this->startSdkServerServing([]);
        $client = $this->initializedClient();
        $client->getValue(
            'unknown-flag',
            false,
            new Context(id: 'user-42', traits: ['biography' => str_repeat('a', 1_100_000)]),
        );
        $client->dispose();

        $this->endARequestAfterTheFlushInterval();

        [$report] = $this->telemetryRequestsReceived();
        self::assertGreaterThan(1_100_000, (int) $report['headers']['content-length']);
        self::assertArrayNotHasKey('expect', $report['headers']);
    }

    public function testFloatsInsideAnArrayInCodeDefaultValueAreWrittenAsEverySdkWritesThemForItsValueId(): void
    {
        $this->startSdkServerServing([]);
        $client = $this->initializedClient();
        $client->getValue(
            'unknown-rates',
            ['rate' => 0.00001, 'tiny' => 1e-7, 'large' => 1.5e25, 'whole' => 2.0, 'count' => 3],
        );
        $client->dispose();

        $this->endARequestAfterTheFlushInterval();

        [$evaluation] = $this->evaluationsOfTheOnlyReport();
        self::assertIsArray($evaluation['event']);
        self::assertSame(['valueId' => '5UZ8oePEJ8nwj0rzUKAEQx'], $evaluation['event']['defaultValue']);
    }

    public function testNestedArraysNullsAndTextInsideAnArrayInCodeDefaultValueAreWrittenAsCompactJsonForItsValueId(): void
    {
        $this->startSdkServerServing([]);
        $client = $this->initializedClient();
        $client->getValue(
            'unknown-layout',
            ['nested' => ['empty' => null, 'list' => [true, false, 'x/y', 'é "quoted"']], 'items' => []],
        );
        $client->dispose();

        $this->endARequestAfterTheFlushInterval();

        [$evaluation] = $this->evaluationsOfTheOnlyReport();
        self::assertIsArray($evaluation['event']);
        self::assertSame(['valueId' => '7FH1KDvBg8m4dacuIFccFa'], $evaluation['event']['defaultValue']);
    }

    public function testAListInCodeDefaultValueIsWrittenAsAJsonArrayForItsValueId(): void
    {
        $this->startSdkServerServing([]);
        $client = $this->initializedClient();
        $client->getValue('unknown-sizes', [1.0, 2.5, 'a']);
        $client->dispose();

        $this->endARequestAfterTheFlushInterval();

        [$evaluation] = $this->evaluationsOfTheOnlyReport();
        self::assertIsArray($evaluation['event']);
        self::assertSame(['valueId' => '3zpiznvvwmIURTOOomhkQ4'], $evaluation['event']['defaultValue']);
    }

    public function testFloatsThatAreNotFiniteInsideAnArrayInCodeDefaultValueAreWrittenAsNullForItsValueId(): void
    {
        $this->startSdkServerServing([]);
        $client = $this->initializedClient();
        $client->getValue('unknown-limits', ['limit' => INF, 'ratio' => NAN]);
        $client->dispose();

        $this->endARequestAfterTheFlushInterval();

        [$evaluation] = $this->evaluationsOfTheOnlyReport();
        self::assertIsArray($evaluation['event']);
        self::assertSame(['valueId' => '4ie6l8JKVqHiqFLq2vcHkh'], $evaluation['event']['defaultValue']);
    }

    public function testAStringOfFiveHundredCharactersAndALineBreakIsReportedByItsValueId(): void
    {
        $this->startSdkServerServing([]);
        $client = $this->initializedClient();
        $client->getValue('unknown-banner', str_repeat('a', 500) . "\n");
        $client->dispose();

        $this->endARequestAfterTheFlushInterval();

        [$evaluation] = $this->evaluationsOfTheOnlyReport();
        self::assertIsArray($evaluation['event']);
        self::assertSame(['valueId' => '05VR5L61GBE5pctML1Fnjf'], $evaluation['event']['defaultValue']);
    }

    public function testEntriesOfTheStoredTelemetryThatAreNumbersAreLeftOutOfTheReport(): void
    {
        $this->startSdkServerServing([]);
        $client = $this->initializedClient();
        $client->getValue('unknown-flag', false);
        $client->dispose();
        file_put_contents(
            $this->localStoreFolder() . DIRECTORY_SEPARATOR . 'telemetry.jsonl',
            '{"collectingSince":1794484800000,"evaluationCounts":{"7":4},"capturedContexts":{"7":"7"},'
            . '"invalidContextCounts":{"7":2}}' . "\n",
            FILE_APPEND,
        );

        $this->endARequestAfterTheFlushInterval();

        self::assertSame(
            ['unknown-flag'],
            array_column(array_column($this->evaluationsOfTheOnlyReport(), 'event'), 'key'),
        );
        self::assertSame([], $this->capturedContextsOfTheOnlyReport());
        self::assertSame(
            [['count' => 2, 'event' => ['reason' => 'invalid-context-kind', 'kindSample' => '7']]],
            $this->invalidContextsOfTheOnlyReport(),
        );
    }

    public function testTelemetryWhoseTallyCannotBeWrittenIsNotKeptAndLogsOneWarningNamingTheFile(): void
    {
        $this->startSdkServerServing([]);
        $client = $this->initializedClient();
        $client->getValue('unknown-flag', false);
        $tallyFile = $this->localStoreFolder() . DIRECTORY_SEPARATOR . 'telemetry-tally.json';
        mkdir($tallyFile);

        $client->dispose();

        self::assertSame([['file' => $tallyFile]], $this->logger->contextsLoggedAt(LogLevel::WARNING));
        self::assertNotContains('telemetry.jsonl', TemporaryFolder::entriesOf($this->localStoreFolder()));
    }

    public function testARefusalThatCannotBeRecordedLogsAWarningNamingTheFile(): void
    {
        $this->startSdkServerServing([], [['status' => 401]]);
        $client = $this->initializedClient();
        $client->getValue('unknown-flag', false);
        $client->dispose();
        $fileOfTheSendingPause = $this->localStoreFolder() . DIRECTORY_SEPARATOR . 'telemetry-pause-'
            . hash('sha256', $this->server?->url . '/server/telemetry/v1') . '.json';
        mkdir($fileOfTheSendingPause);

        $this->endARequestAfterTheFlushInterval();

        self::assertSame(
            [['status' => 401], ['file' => $fileOfTheSendingPause]],
            $this->logger->contextsLoggedAt(LogLevel::WARNING),
        );
    }

    public function testARequestThatReadNothingAddsNothingToTheTelemetryOfTheLocalStore(): void
    {
        $this->startSdkServerServing([]);
        $client = $this->initializedClient();

        $client->dispose();

        self::assertNotContains('telemetry.jsonl', TemporaryFolder::entriesOf($this->localStoreFolder()));
        self::assertNotContains('telemetry-tally.json', TemporaryFolder::entriesOf($this->localStoreFolder()));
    }

    /**
     * @param array<string, array<string, mixed>> $configs
     * @param list<ScriptedAnswer> $answersToReports
     */
    private function startSdkServerServing(array $configs, array $answersToReports = [['status' => 200]]): void
    {
        $this->server = ScriptedSdkServer::answering([
            ['status' => 200, 'body' => self::serverSdkPayloadOf($configs)],
            ...$answersToReports,
        ]);
    }

    private function client(
        int $flushInterval,
        ?Metadata $metadata = null,
        int $eventQueueLimit = 5_000,
    ): ConfigDirectorClient {
        if ($this->server === null) {
            throw new \LogicException('The test starts the SDK server before it creates a client.');
        }

        return ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            metadata: $metadata,
            connection: new ConnectionOptions(url: $this->server->url, storagePath: $this->storagePath),
            logger: $this->logger,
            telemetry: new TelemetryOptions(eventQueueLimit: $eventQueueLimit, flushInterval: $flushInterval),
        );
    }

    private function initializedClient(int $eventQueueLimit = 5_000): ConfigDirectorClient
    {
        $client = $this->client(flushInterval: 60_000, eventQueueLimit: $eventQueueLimit);
        $client->initialize();

        return $client;
    }

    private function endARequestAfterTheFlushInterval(?Metadata $metadata = null, int $eventQueueLimit = 5_000): void
    {
        $client = $this->client(1, $metadata, $eventQueueLimit);
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
     * @return list<array{method: string, path: string, headers: array<string, string>, body: mixed}>
     */
    private function telemetryRequestsReceived(): array
    {
        return array_values(array_filter(
            $this->server?->requestsReceived() ?? [],
            static fn(array $request): bool => $request['path'] === '/server/telemetry/v1',
        ));
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
     * @return list<array{count: mixed, event: mixed}>
     */
    private function evaluationsOfTheOnlyReport(): array
    {
        $body = $this->bodyOfTheOnlyReport();
        self::assertIsArray($body['aggregatedEvents']);
        self::assertIsArray($body['aggregatedEvents']['evaluatedConfig']);

        $evaluations = [];
        foreach ($body['aggregatedEvents']['evaluatedConfig'] as $entry) {
            self::assertIsArray($entry);
            $evaluations[] = ['count' => $entry['count'], 'event' => $entry['event']];
        }

        return $evaluations;
    }

    /**
     * @return list<mixed>
     */
    private function capturedContextsOfTheOnlyReport(): array
    {
        $body = $this->bodyOfTheOnlyReport();
        self::assertIsArray($body['discreteEvents']);
        self::assertIsArray($body['discreteEvents']['capturedContexts']);

        return array_values($body['discreteEvents']['capturedContexts']);
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

    /**
     * @param array<string, array<string, mixed>> $configs
     *
     * @return array<string, mixed>
     */
    private static function serverSdkPayloadOf(array $configs): array
    {
        return [
            'payloadVersion' => 1,
            'environmentId' => 'environment-1',
            'projectId' => 'project-1',
            'configs' => $configs === [] ? new \stdClass() : $configs,
            'segments' => new \stdClass(),
            'kind' => 'full',
            'timestamp' => '2026-11-13T12:00:00.000000Z',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function configServingEveryContext(string $type, string $value, string $valueId): array
    {
        return [
            'id' => "config-{$valueId}",
            'key' => "key-of-{$valueId}",
            'type' => $type,
            'variations' => [],
            'target' => ['rules' => [], 'defaultValue' => $value, 'defaultValueId' => $valueId],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function integerConfigServingFiftyToTheProPlan(): array
    {
        return [
            'id' => 'config-page-size',
            'key' => 'page-size',
            'type' => 'integer',
            'variations' => [
                ['id' => 'variation-50', 'name' => 'Large', 'value' => '50'],
                ['id' => 'variation-25', 'name' => 'Regular', 'value' => '25'],
            ],
            'target' => [
                'rules' => [
                    [
                        'id' => 'rule-pro-plan',
                        'order' => 0,
                        'type' => 'conditional',
                        'target' => 'value',
                        'percentages' => null,
                        'value' => '50',
                        'valueId' => 'value-50',
                        'variationId' => 'variation-50',
                        'conditions' => [
                            [
                                'kind' => 'attribute',
                                'id' => 'condition-pro-plan',
                                'attribute' => 'traits',
                                'trait' => '/plan',
                                'operator' => 'equals',
                                'targetType' => 'text',
                                'targetValues' => ['pro'],
                            ],
                        ],
                    ],
                ],
                'defaultValue' => '25',
                'defaultValueId' => 'value-25',
                'defaultVariationId' => 'variation-25',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function booleanConfigServedByARollout(): array
    {
        return [
            'id' => 'config-new-checkout',
            'key' => 'new-checkout',
            'type' => 'boolean',
            'variations' => [
                ['id' => 'variation-checkout-on', 'name' => 'On', 'value' => 'true'],
                ['id' => 'variation-checkout-off', 'name' => 'Off', 'value' => 'false'],
            ],
            'target' => [
                'rules' => [
                    [
                        'id' => 'rule-checkout-rollout',
                        'order' => 0,
                        'type' => 'percentage',
                        'target' => 'percentage',
                        'percentages' => [
                            [
                                'id' => 'share-checkout-on',
                                'percentage' => 100.0,
                                'value' => 'true',
                                'valueId' => 'value-checkout-on',
                                'variationId' => 'variation-checkout-on',
                            ],
                        ],
                        'value' => null,
                        'valueId' => null,
                        'conditions' => null,
                    ],
                ],
                'defaultValue' => 'false',
                'defaultValueId' => 'value-checkout-off',
                'defaultVariationId' => 'variation-checkout-off',
            ],
        ];
    }
}
