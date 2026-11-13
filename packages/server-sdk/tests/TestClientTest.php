<?php

declare(strict_types=1);

namespace ConfigDirector\Tests;

use ConfigDirector\ConfigDirectorConnectionException;
use ConfigDirector\ConfigDirectorValidationException;
use ConfigDirector\ConfigEvaluation;
use ConfigDirector\ConfigState;
use ConfigDirector\ConfigsUpdatedEvent;
use ConfigDirector\ConnectionErrorEvent;
use ConfigDirector\Context;
use ConfigDirector\EvaluationReason;
use ConfigDirector\Testing\ConfigDirectorTesting;
use ConfigDirector\Tests\Support\PhpProcess;
use ConfigDirector\Tests\Support\RecordingLogger;
use ConfigDirector\Tests\Support\TemporaryFolder;
use PHPUnit\Framework\TestCase;

final class TestClientTest extends TestCase
{
    private ?string $folder = null;

    #[\Override]
    protected function tearDown(): void
    {
        if ($this->folder !== null) {
            TemporaryFolder::remove($this->folder);
        }
    }

    public function testATestClientServesEachOfItsValuesAfterInitialize(): void
    {
        $testClient = ConfigDirectorTesting::createTestClient(
            values: [
                'new-checkout' => true,
                'max-items' => 20,
                'discount-rate' => 0.15,
                'greeting' => 'hello',
                'theme' => ['color' => 'blue', 'sizes' => [1, 2]],
            ],
            logger: new RecordingLogger(),
        );

        $testClient->client->initialize();

        self::assertTrue($testClient->client->getValue('new-checkout', false));
        self::assertSame(20, $testClient->client->getValue('max-items', 0));
        self::assertSame(0.15, $testClient->client->getValue('discount-rate', 0.0));
        self::assertSame('hello', $testClient->client->getValue('greeting', ''));
        self::assertSame(['color' => 'blue', 'sizes' => [1, 2]], $testClient->client->getValue('theme', []));
    }

    public function testATestClientIsNotInitializedWhenCreated(): void
    {
        $testClient = ConfigDirectorTesting::createTestClient(
            values: ['new-checkout' => true],
            logger: new RecordingLogger(),
        );

        self::assertFalse($testClient->client->isReady());
        self::assertEquals(
            new ConfigEvaluation('new-checkout', false, null, null, true, EvaluationReason::ClientNotReady, null),
            $testClient->client->evaluate('new-checkout', false),
        );
    }

    public function testATestValueIsAFoundMatchWithoutAValueIdOrAVariation(): void
    {
        $testClient = ConfigDirectorTesting::createTestClient(
            values: ['new-checkout' => true],
            logger: new RecordingLogger(),
        );
        $context = new Context(id: 'user-123');

        $testClient->client->initialize();

        self::assertEquals(
            new ConfigEvaluation('new-checkout', true, null, null, false, EvaluationReason::FoundMatch, $context),
            $testClient->client->evaluate('new-checkout', false, $context),
        );
    }

    public function testEveryContextReceivesTheSameTestValue(): void
    {
        $testClient = ConfigDirectorTesting::createTestClient(values: ['max-items' => 20], logger: new RecordingLogger());

        $testClient->client->initialize();

        self::assertSame(20, $testClient->client->getValue('max-items', 0));
        self::assertSame(20, $testClient->client->getValue('max-items', 0, new Context(id: 'user-123')));
        self::assertSame(
            20,
            $testClient->client->getValue(
                'max-items',
                0,
                new Context(id: 'org-9', kind: 'organization', traits: ['plan' => 'pro']),
            ),
        );
    }

    public function testGetAllConfigsReturnsTheStateOfEachTestValue(): void
    {
        $testClient = ConfigDirectorTesting::createTestClient(
            values: ['new-checkout' => true, 'greeting' => 'hello'],
            logger: new RecordingLogger(),
        );

        $testClient->client->initialize();

        self::assertEquals(
            [
                'new-checkout' => new ConfigState('test-config:new-checkout', 'new-checkout', 'boolean', 'true'),
                'greeting' => new ConfigState('test-config:greeting', 'greeting', 'string', 'hello'),
            ],
            $testClient->client->getAllConfigs(),
        );
    }

    public function testAKeyWithoutATestValueReturnsTheInCodeDefaultValueWithConfigStateMissing(): void
    {
        $testClient = ConfigDirectorTesting::createTestClient(
            values: ['new-checkout' => true],
            logger: new RecordingLogger(),
        );

        $testClient->client->initialize();

        self::assertEquals(
            new ConfigEvaluation('page-size', 10, null, null, true, EvaluationReason::ConfigStateMissing, null),
            $testClient->client->evaluate('page-size', 10),
        );
    }

    public function testSetValueNamesTheKeyInConfigsUpdatedThenCallsItsWatchBeforeItReturns(): void
    {
        $testClient = ConfigDirectorTesting::createTestClient(values: ['max-items' => 20], logger: new RecordingLogger());
        $deliveries = [];
        $testClient->client->on('configsUpdated', function (ConfigsUpdatedEvent $event) use (&$deliveries): void {
            $deliveries[] = ['configsUpdated', $event->keys, $event->removedKeys];
        });
        $testClient->client->watch('max-items', 0, function (int $value) use (&$deliveries): void {
            $deliveries[] = ['watch', $value];
        });
        $testClient->client->initialize();
        $deliveries = [];

        $testClient->setValue('max-items', 30);

        self::assertSame([['configsUpdated', ['max-items'], []], ['watch', 30]], $deliveries);
    }

    public function testSetValueOnAnInitializedClientChangesWhatTheNextReadReturns(): void
    {
        $testClient = ConfigDirectorTesting::createTestClient(values: ['max-items' => 20], logger: new RecordingLogger());
        $testClient->client->initialize();

        $testClient->setValue('max-items', 30);

        self::assertSame(30, $testClient->client->getValue('max-items', 0));
    }

    public function testSetValueOfAnotherKeyDoesNotCallAWatchOnAKeyItLeftAlone(): void
    {
        $testClient = ConfigDirectorTesting::createTestClient(
            values: ['max-items' => 20, 'greeting' => 'hello'],
            logger: new RecordingLogger(),
        );
        $watchedValues = [];
        $testClient->client->watch('greeting', '', function (string $value) use (&$watchedValues): void {
            $watchedValues[] = $value;
        });
        $testClient->client->initialize();
        $watchedValues = [];

        $testClient->setValue('max-items', 30);

        self::assertSame([], $watchedValues);
    }

    public function testSetValueWithTheValueAKeyAlreadyHasFiresNothing(): void
    {
        $testClient = ConfigDirectorTesting::createTestClient(values: ['max-items' => 20], logger: new RecordingLogger());
        $deliveries = [];
        $testClient->client->on('configsUpdated', function (ConfigsUpdatedEvent $event) use (&$deliveries): void {
            $deliveries[] = ['configsUpdated', $event->keys, $event->removedKeys];
        });
        $testClient->client->watch('max-items', 0, function (int $value) use (&$deliveries): void {
            $deliveries[] = ['watch', $value];
        });
        $testClient->client->initialize();
        $deliveries = [];

        $testClient->setValue('max-items', 20);

        self::assertSame([], $deliveries);
    }

    public function testAValueSetBeforeInitializeIsServedByIt(): void
    {
        $testClient = ConfigDirectorTesting::createTestClient(values: ['max-items' => 20], logger: new RecordingLogger());
        $testClient->setValue('max-items', 30);
        $testClient->setValue('greeting', 'hello');

        $testClient->client->initialize();

        self::assertSame(30, $testClient->client->getValue('max-items', 0));
        self::assertSame('hello', $testClient->client->getValue('greeting', ''));
    }

    public function testRemoveValueMakesTheNextReadReturnTheInCodeDefaultValueWithConfigStateMissing(): void
    {
        $testClient = ConfigDirectorTesting::createTestClient(values: ['max-items' => 20], logger: new RecordingLogger());
        $testClient->client->initialize();

        $testClient->removeValue('max-items');

        self::assertEquals(
            new ConfigEvaluation('max-items', 10, null, null, true, EvaluationReason::ConfigStateMissing, null),
            $testClient->client->evaluate('max-items', 10),
        );
    }

    public function testRemoveValueNamesTheKeyAsRemovedThenCallsOnlyItsWatchWithTheInCodeDefaultValue(): void
    {
        $testClient = ConfigDirectorTesting::createTestClient(
            values: ['max-items' => 20, 'greeting' => 'hello'],
            logger: new RecordingLogger(),
        );
        $deliveries = [];
        $testClient->client->on('configsUpdated', function (ConfigsUpdatedEvent $event) use (&$deliveries): void {
            $deliveries[] = ['configsUpdated', $event->keys, $event->removedKeys];
        });
        $testClient->client->watch('max-items', 10, function (int $value) use (&$deliveries): void {
            $deliveries[] = ['watch on max-items', $value];
        });
        $testClient->client->watch('greeting', '', function (string $value) use (&$deliveries): void {
            $deliveries[] = ['watch on greeting', $value];
        });
        $testClient->client->initialize();
        $deliveries = [];

        $testClient->removeValue('max-items');

        self::assertSame([['configsUpdated', [], ['max-items']], ['watch on max-items', 10]], $deliveries);
    }

    public function testAValueRemovedBeforeInitializeIsMissingAfterIt(): void
    {
        $testClient = ConfigDirectorTesting::createTestClient(
            values: ['max-items' => 20, 'greeting' => 'hello'],
            logger: new RecordingLogger(),
        );
        $testClient->removeValue('max-items');

        $testClient->client->initialize();

        self::assertEquals(
            new ConfigEvaluation('max-items', 10, null, null, true, EvaluationReason::ConfigStateMissing, null),
            $testClient->client->evaluate('max-items', 10),
        );
        self::assertSame('hello', $testClient->client->getValue('greeting', ''));
    }

    public function testReplaceValuesServesExactlyTheNewValues(): void
    {
        $testClient = ConfigDirectorTesting::createTestClient(
            values: ['max-items' => 20, 'greeting' => 'hello'],
            logger: new RecordingLogger(),
        );
        $testClient->client->initialize();

        $testClient->replaceValues(['greeting' => 'bonjour', 'new-checkout' => true]);

        self::assertEquals(
            [
                'greeting' => new ConfigState('test-config:greeting', 'greeting', 'string', 'bonjour'),
                'new-checkout' => new ConfigState('test-config:new-checkout', 'new-checkout', 'boolean', 'true'),
            ],
            $testClient->client->getAllConfigs(),
        );
    }

    public function testReplaceValuesNamesTheChangedAndRemovedKeysThenCallsTheirWatches(): void
    {
        $testClient = ConfigDirectorTesting::createTestClient(
            values: ['max-items' => 20, 'greeting' => 'hello', 'theme' => 'dark'],
            logger: new RecordingLogger(),
        );
        $deliveries = [];
        $testClient->client->on('configsUpdated', function (ConfigsUpdatedEvent $event) use (&$deliveries): void {
            $deliveries[] = ['configsUpdated', $event->keys, $event->removedKeys];
        });
        $testClient->client->watch('max-items', 10, function (int $value) use (&$deliveries): void {
            $deliveries[] = ['watch on max-items', $value];
        });
        $testClient->client->watch('greeting', '', function (string $value) use (&$deliveries): void {
            $deliveries[] = ['watch on greeting', $value];
        });
        $testClient->client->watch('theme', '', function (string $value) use (&$deliveries): void {
            $deliveries[] = ['watch on theme', $value];
        });
        $testClient->client->initialize();
        $deliveries = [];

        $testClient->replaceValues(['greeting' => 'bonjour', 'theme' => 'dark', 'new-checkout' => true]);

        self::assertSame(
            [
                ['configsUpdated', ['greeting', 'new-checkout'], ['max-items']],
                ['watch on greeting', 'bonjour'],
                ['watch on max-items', 10],
            ],
            $deliveries,
        );
    }

    public function testCreateTestClientRejectsAValueItCannotServe(): void
    {
        $this->expectException(ConfigDirectorValidationException::class);

        ConfigDirectorTesting::createTestClient(
            // @phpstan-ignore argument.type
            values: ['max-items' => null],
            logger: new RecordingLogger(),
        );
    }

    public function testATestClientCreatesNoLocalStoreAndMakesNoNetworkCall(): void
    {
        $this->folder = TemporaryFolder::create();

        $output = PhpProcess::running(
            __DIR__ . '/Processes/use-a-test-client.php',
            [],
            [
                'sys_temp_dir' => $this->folder,
                'disable_functions' => 'curl_init,curl_multi_init,fsockopen,pfsockopen,stream_socket_client',
            ],
        )->finished()->output;

        self::assertSame("new-checkout: false\nmax-items: 30\ndisposed\n", $output);
        self::assertSame([], TemporaryFolder::entriesOf($this->folder));
    }

    public function testWithoutALoggerATestClientWritesWarningsThroughErrorLog(): void
    {
        $testClient = ConfigDirectorTesting::createTestClient(values: ['new-checkout' => true], timeout: 7);
        $testClient->holdInitialization();

        $this->expectOutputRegex('/^\[ConfigDirector:php-server-sdk\] warning: [^\r\n{]* 7\D[^\r\n{]*\R$/');

        $testClient->client->initialize();
    }

    public function testWithoutALoggerATestClientWritesNoDebugMessages(): void
    {
        $testClient = ConfigDirectorTesting::createTestClient(values: ['new-checkout' => true]);

        $this->expectOutputString('');

        $testClient->client->initialize();
        $testClient->client->getValue('new-checkout', false);
    }

    public function testAContextWithAnInvalidKindIsLoggedOnEveryRead(): void
    {
        $logger = new RecordingLogger();
        $testClient = ConfigDirectorTesting::createTestClient(values: ['max-items' => 20], logger: $logger);
        $testClient->client->initialize();

        $testClient->client->getValue('max-items', 0, new Context(id: 'device-1', kind: 'Device'));
        $testClient->client->getValue('max-items', 0, new Context(id: 'device-2', kind: 'Device'));

        self::assertSame(
            [['kindSample' => 'Device'], ['kindSample' => 'Device']],
            $logger->contextsLoggedAt('error'),
        );
    }

    public function testCreateTestClientRejectsATimeoutOfZero(): void
    {
        $this->expectException(ConfigDirectorValidationException::class);

        ConfigDirectorTesting::createTestClient(timeout: 0, logger: new RecordingLogger());
    }

    public function testSetValueRejectsABlankKey(): void
    {
        $testClient = ConfigDirectorTesting::createTestClient(logger: new RecordingLogger());

        $this->expectException(ConfigDirectorValidationException::class);

        $testClient->setValue(' ', true);
    }

    public function testSetValueRejectsAValueItCannotServeAndTheKeyKeepsItsValue(): void
    {
        $testClient = ConfigDirectorTesting::createTestClient(
            values: ['discount-rate' => 0.15],
            logger: new RecordingLogger(),
        );
        $testClient->client->initialize();

        try {
            $testClient->setValue('discount-rate', NAN);
            self::fail('setValue accepted a float that is not finite.');
        } catch (ConfigDirectorValidationException) {
        }

        self::assertSame(0.15, $testClient->client->getValue('discount-rate', 0.0));
    }

    public function testReplaceValuesRejectsAValueItCannotServeAndKeepsTheValuesItHad(): void
    {
        $testClient = ConfigDirectorTesting::createTestClient(
            values: ['max-items' => 20, 'greeting' => 'hello'],
            logger: new RecordingLogger(),
        );

        try {
            $testClient->replaceValues(['max-items' => 30, 'theme' => ['shade' => new \DateTimeImmutable()]]);
            self::fail('replaceValues accepted an object other than stdClass.');
        } catch (ConfigDirectorValidationException) {
        }
        $testClient->client->initialize();

        self::assertEquals(
            [
                'max-items' => new ConfigState('test-config:max-items', 'max-items', 'integer', '20'),
                'greeting' => new ConfigState('test-config:greeting', 'greeting', 'string', 'hello'),
            ],
            $testClient->client->getAllConfigs(),
        );
    }

    public function testAHeldInitializeWaitsForTheTimeoutLogsItAndReturnsNotReady(): void
    {
        $logger = new RecordingLogger();
        $testClient = ConfigDirectorTesting::createTestClient(
            values: ['new-checkout' => true],
            timeout: 50,
            logger: $logger,
        );
        $testClient->holdInitialization();

        $startedAt = hrtime(true);
        $testClient->client->initialize();
        $waitedMilliseconds = (hrtime(true) - $startedAt) / 1_000_000;

        self::assertGreaterThanOrEqual(50, $waitedMilliseconds);
        self::assertFalse($testClient->client->isReady());
        self::assertSame([['timeout' => 50]], $logger->contextsLoggedAt('warning'));
    }

    public function testAHeldInitializeWaitsForTheWholeTimeoutWhenASleepWakesEarly(): void
    {
        $output = PhpProcess::running(__DIR__ . '/Processes/hold-with-a-sleep-that-wakes-early.php', [])
            ->finished()
            ->output;

        self::assertStringStartsWith("woke early\n", $output);
        self::assertMatchesRegularExpression('/^waited (\d+) milliseconds$/m', $output);
        preg_match('/^waited (\d+) milliseconds$/m', $output, $waited);
        self::assertGreaterThanOrEqual(50, (int) ($waited[1] ?? 0));
    }

    public function testCompleteInitializationAfterAHeldInitializeMakesTheClientReadyAndFiresClientReadyOnce(): void
    {
        $testClient = ConfigDirectorTesting::createTestClient(
            values: ['new-checkout' => true, 'max-items' => 20],
            timeout: 50,
            logger: new RecordingLogger(),
        );
        $deliveries = [];
        $testClient->client->on('configsUpdated', function (ConfigsUpdatedEvent $event) use (&$deliveries): void {
            $deliveries[] = ['configsUpdated', $event->keys, $event->removedKeys];
        });
        $testClient->client->watch('max-items', 0, function (int $value) use (&$deliveries): void {
            $deliveries[] = ['watch', $value];
        });
        $testClient->client->on('clientReady', function () use (&$deliveries): void {
            $deliveries[] = ['clientReady'];
        });
        $testClient->holdInitialization();
        $testClient->client->initialize();

        $testClient->completeInitialization();

        self::assertTrue($testClient->client->isReady());
        self::assertSame(
            [['configsUpdated', ['new-checkout', 'max-items'], []], ['watch', 20], ['clientReady']],
            $deliveries,
        );
    }

    public function testCompleteInitializationBeforeInitializeCancelsTheHold(): void
    {
        $testClient = ConfigDirectorTesting::createTestClient(
            values: ['new-checkout' => true],
            timeout: 1_000,
            logger: new RecordingLogger(),
        );
        $testClient->holdInitialization();
        $testClient->completeInitialization();

        $testClient->client->initialize();

        self::assertTrue($testClient->client->isReady());
    }

    public function testReplaceValuesCancelsAHold(): void
    {
        $testClient = ConfigDirectorTesting::createTestClient(
            values: ['new-checkout' => true],
            timeout: 1_000,
            logger: new RecordingLogger(),
        );
        $testClient->holdInitialization();
        $testClient->replaceValues(['new-checkout' => false]);

        $testClient->client->initialize();

        self::assertTrue($testClient->client->isReady());
    }

    public function testCompleteInitializationBeforeInitializeLeavesAFailureInPlace(): void
    {
        $testClient = ConfigDirectorTesting::createTestClient(values: ['max-items' => 20], logger: new RecordingLogger());
        $testClient->failInitialization();
        $testClient->completeInitialization();

        $testClient->client->initialize();

        self::assertFalse($testClient->client->isReady());
    }

    public function testReplaceValuesCancelsAFailure(): void
    {
        $testClient = ConfigDirectorTesting::createTestClient(values: ['max-items' => 20], logger: new RecordingLogger());
        $testClient->failInitialization();
        $testClient->replaceValues(['max-items' => 30]);

        $testClient->client->initialize();

        self::assertTrue($testClient->client->isReady());
    }

    public function testAFailedInitializeOfAReadyClientKeepsItsValuesUntilTheNextInitializeServesTheNewOnes(): void
    {
        $testClient = ConfigDirectorTesting::createTestClient(values: ['max-items' => 20], logger: new RecordingLogger());
        $testClient->client->initialize();
        $testClient->failInitialization();
        $testClient->client->initialize();

        $testClient->setValue('max-items', 30);

        self::assertTrue($testClient->client->isReady());
        self::assertSame(20, $testClient->client->getValue('max-items', 0));

        $testClient->client->initialize();

        self::assertSame(30, $testClient->client->getValue('max-items', 0));
    }

    public function testAHeldInitializeWaitsForThreeSecondsWithoutATimeout(): void
    {
        $logger = new RecordingLogger();
        $testClient = ConfigDirectorTesting::createTestClient(values: ['new-checkout' => true], logger: $logger);
        $testClient->holdInitialization();

        $testClient->client->initialize();

        self::assertSame([['timeout' => 3_000]], $logger->contextsLoggedAt('warning'));
    }

    public function testAFailedInitializeReturnsAtOnceNotReadyWithoutClientReadyAndLogsOneError(): void
    {
        $logger = new RecordingLogger();
        $testClient = ConfigDirectorTesting::createTestClient(
            values: ['new-checkout' => true],
            timeout: 1_000,
            logger: $logger,
        );
        $readyCount = 0;
        $testClient->client->on('clientReady', function () use (&$readyCount): void {
            $readyCount++;
        });
        $testClient->failInitialization();

        $startedAt = hrtime(true);
        $testClient->client->initialize();
        $waitedMilliseconds = (hrtime(true) - $startedAt) / 1_000_000;

        self::assertLessThan(1_000, $waitedMilliseconds);
        self::assertFalse($testClient->client->isReady());
        self::assertSame(0, $readyCount);
        self::assertCount(1, $logger->contextsLoggedAt('error'));
    }

    public function testAFailedInitializeFiresConnectionErrorWithTheStatusOfARefusedKey(): void
    {
        $testClient = ConfigDirectorTesting::createTestClient(
            values: ['new-checkout' => true],
            logger: new RecordingLogger(),
        );
        $errors = [];
        $testClient->client->on('connectionError', function (ConnectionErrorEvent $event) use (&$errors): void {
            $errors[] = $event->error;
        });
        $testClient->failInitialization();

        $testClient->client->initialize();

        self::assertCount(1, $errors);
        self::assertInstanceOf(ConfigDirectorConnectionException::class, $errors[0]);
        self::assertSame(401, $errors[0]->status);
    }

    public function testFailInitializationAfterAHeldInitializeFailsItAndALaterCompletionDoesNothing(): void
    {
        $logger = new RecordingLogger();
        $testClient = ConfigDirectorTesting::createTestClient(
            values: ['new-checkout' => true],
            timeout: 50,
            logger: $logger,
        );
        $errors = [];
        $testClient->client->on('connectionError', function (ConnectionErrorEvent $event) use (&$errors): void {
            $errors[] = $event->error;
        });
        $testClient->holdInitialization();
        $testClient->client->initialize();

        $testClient->failInitialization();
        $testClient->completeInitialization();

        self::assertCount(1, $errors);
        self::assertCount(1, $logger->contextsLoggedAt('error'));
        self::assertFalse($testClient->client->isReady());
    }

    public function testAValueSetWhileInitializationIsHeldIsServedOnceItCompletes(): void
    {
        $testClient = ConfigDirectorTesting::createTestClient(
            values: ['max-items' => 20],
            timeout: 50,
            logger: new RecordingLogger(),
        );
        $testClient->holdInitialization();
        $testClient->client->initialize();
        $testClient->setValue('max-items', 30);

        $testClient->completeInitialization();

        self::assertSame(30, $testClient->client->getValue('max-items', 0));
    }

    public function testInitializingAgainWhileInitializationIsHeldServesTheValuesAtOnce(): void
    {
        $testClient = ConfigDirectorTesting::createTestClient(
            values: ['max-items' => 20],
            timeout: 50,
            logger: new RecordingLogger(),
        );
        $testClient->holdInitialization();
        $testClient->client->initialize();

        $testClient->client->initialize();

        self::assertTrue($testClient->client->isReady());
        self::assertSame(20, $testClient->client->getValue('max-items', 0));
    }

    public function testTheInitializeAfterAFailedOneServesTheValuesStoredInTheMeantime(): void
    {
        $testClient = ConfigDirectorTesting::createTestClient(values: ['max-items' => 20], logger: new RecordingLogger());
        $testClient->failInitialization();
        $testClient->client->initialize();
        $testClient->setValue('max-items', 30);

        $testClient->client->initialize();

        self::assertTrue($testClient->client->isReady());
        self::assertSame(30, $testClient->client->getValue('max-items', 0));
    }

    public function testCompleteInitializationWithNothingHeldLeavesAClientThatWasNeverInitializedNotReady(): void
    {
        $testClient = ConfigDirectorTesting::createTestClient(values: ['max-items' => 20], logger: new RecordingLogger());
        $readyCount = 0;
        $testClient->client->on('clientReady', function () use (&$readyCount): void {
            $readyCount++;
        });

        $testClient->completeInitialization();

        self::assertFalse($testClient->client->isReady());
        self::assertSame(0, $readyCount);
    }

    public function testAValueSetAfterAFailedInitializeLeavesTheClientNotReady(): void
    {
        $testClient = ConfigDirectorTesting::createTestClient(values: ['max-items' => 20], logger: new RecordingLogger());
        $testClient->failInitialization();
        $testClient->client->initialize();

        $testClient->setValue('max-items', 30);

        self::assertFalse($testClient->client->isReady());
    }

    public function testAfterDisposeTheControlsDeliverNothing(): void
    {
        $logger = new RecordingLogger();
        $testClient = ConfigDirectorTesting::createTestClient(
            values: ['max-items' => 20, 'greeting' => 'hello'],
            logger: $logger,
        );
        $testClient->client->initialize();
        $testClient->client->dispose();

        $testClient->setValue('max-items', 30);
        $testClient->removeValue('greeting');
        $testClient->replaceValues(['max-items' => 40]);
        $testClient->completeInitialization();
        $testClient->failInitialization();

        self::assertFalse($testClient->client->isReady());
        self::assertEquals(
            new ConfigEvaluation('max-items', 10, null, null, true, EvaluationReason::ClientNotReady, null),
            $testClient->client->evaluate('max-items', 10),
        );
        self::assertSame([], $logger->contextsLoggedAt('error'));
    }

    public function testCompletingAnInitializationHeldWhenTheClientWasDisposedDeliversNothing(): void
    {
        $testClient = ConfigDirectorTesting::createTestClient(
            values: ['max-items' => 20],
            timeout: 50,
            logger: new RecordingLogger(),
        );
        $testClient->holdInitialization();
        $testClient->client->initialize();
        $testClient->client->dispose();

        $testClient->completeInitialization();

        self::assertFalse($testClient->client->isReady());
    }

    public function testAChangeMadeFromAWatchIsDeliveredAfterTheDeliveryBeingHandled(): void
    {
        $testClient = ConfigDirectorTesting::createTestClient(
            values: ['max-items' => 20, 'page-size' => 10, 'greeting' => 'hello'],
            logger: new RecordingLogger(),
        );
        $testClient->client->initialize();
        $deliveries = [];
        $testClient->client->on('configsUpdated', function (ConfigsUpdatedEvent $event) use (&$deliveries): void {
            $deliveries[] = ['configsUpdated', $event->keys];
        });
        $testClient->client->watch(
            'max-items',
            0,
            function (int $value) use (&$deliveries, $testClient): void {
                $deliveries[] = ['watch on max-items', $value];
                $testClient->setValue('greeting', 'bonjour');
            },
        );
        $testClient->client->watch('page-size', 0, function (int $value) use (&$deliveries): void {
            $deliveries[] = ['watch on page-size', $value];
        });
        $testClient->client->watch('greeting', '', function (string $value) use (&$deliveries): void {
            $deliveries[] = ['watch on greeting', $value];
        });

        $testClient->replaceValues(['max-items' => 30, 'page-size' => 25, 'greeting' => 'hello']);

        self::assertSame(
            [
                ['configsUpdated', ['max-items', 'page-size']],
                ['watch on max-items', 30],
                ['watch on page-size', 25],
                ['configsUpdated', ['greeting']],
                ['watch on greeting', 'bonjour'],
            ],
            $deliveries,
        );
    }

    public function testAChangeMadeFromAWatchThatThenDisposesTheClientIsNeverDelivered(): void
    {
        $testClient = ConfigDirectorTesting::createTestClient(
            values: ['max-items' => 20, 'greeting' => 'hello'],
            logger: new RecordingLogger(),
        );
        $testClient->client->initialize();
        $testClient->client->watch('max-items', 0, function () use ($testClient): void {
            $testClient->setValue('greeting', 'bonjour');
            $testClient->client->dispose();
        });

        $testClient->setValue('max-items', 30);

        self::assertFalse($testClient->client->isReady());
    }

    public function testAChangeMadeFromAWatchDuringInitializeIsDeliveredAfterItsFirstDeliveryBeforeItReturns(): void
    {
        $testClient = ConfigDirectorTesting::createTestClient(
            values: ['max-items' => 20, 'greeting' => 'hello'],
            logger: new RecordingLogger(),
        );
        $deliveries = [];
        $testClient->client->on('configsUpdated', function (ConfigsUpdatedEvent $event) use (&$deliveries): void {
            $deliveries[] = ['configsUpdated', $event->keys];
        });
        $testClient->client->watch(
            'max-items',
            0,
            function (int $value) use (&$deliveries, $testClient): void {
                $deliveries[] = ['watch on max-items', $value];
                $testClient->setValue('greeting', 'bonjour');
            },
        );
        $testClient->client->watch('greeting', '', function (string $value) use (&$deliveries): void {
            $deliveries[] = ['watch on greeting', $value];
        });
        $testClient->client->on('clientReady', function () use (&$deliveries): void {
            $deliveries[] = ['clientReady'];
        });

        $testClient->client->initialize();

        self::assertSame(
            [
                ['configsUpdated', ['max-items', 'greeting']],
                ['watch on max-items', 20],
                ['watch on greeting', 'hello'],
                ['clientReady'],
                ['configsUpdated', ['greeting']],
                ['watch on greeting', 'bonjour'],
            ],
            $deliveries,
        );
    }
}
