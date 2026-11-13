<?php

declare(strict_types=1);

namespace ConfigDirector\Tests;

use ConfigDirector\ClientHooks;
use ConfigDirector\ConfigDirector;
use ConfigDirector\ConfigDirectorValidationException;
use ConfigDirector\ConfigEvaluatedEvent;
use ConfigDirector\ConfigEvaluation;
use ConfigDirector\Context;
use ConfigDirector\EvaluationReason;
use ConfigDirector\Tests\Support\RecordingLogger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

final class ClientWatchesTest extends TestCase
{
    public function testAWatchRegisteredBeforeInitializeReceivesTheValueDuringIt(): void
    {
        $days = [];
        $client = ConfigDirector::createClient(serverSdkKey: 'server-sdk-key', logger: new RecordingLogger());
        $client->watch('day-of-the-week-config', 'Friday', function (string $day) use (&$days): void {
            $days[] = $day;
        });

        $client->initialize();

        self::assertSame(['Monday'], $days);
    }

    public function testAWatchReceivesTheValueServedToItsContext(): void
    {
        $days = [];
        $client = ConfigDirector::createClient(serverSdkKey: 'server-sdk-key', logger: new RecordingLogger());
        $client->watch(
            'day-of-the-week-config',
            'Friday',
            function (string $day) use (&$days): void {
                $days[] = $day;
            },
            new Context(id: 'user-1', traits: ['plan' => 'pro']),
        );

        $client->initialize();

        self::assertSame(['Tuesday'], $days);
    }

    public function testAWatchRegisteredOnAReadyClientIsNotCalledUntilTheClientReceivesValues(): void
    {
        $days = [];
        $client = ConfigDirector::createClient(serverSdkKey: 'server-sdk-key', logger: new RecordingLogger());
        $client->initialize();

        $client->watch('day-of-the-week-config', 'Friday', function (string $day) use (&$days): void {
            $days[] = $day;
        });

        self::assertSame([], $days);
    }

    public function testAWatchWithANullInCodeDefaultValueIsRejected(): void
    {
        $client = ConfigDirector::createClient(serverSdkKey: 'server-sdk-key', logger: new RecordingLogger());

        $this->expectException(ConfigDirectorValidationException::class);

        $client->watch('day-of-the-week-config', null, static function (): void {});
    }

    public function testEachValueAWatchReceivesFiresConfigEvaluated(): void
    {
        $evaluations = [];
        $client = ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            logger: new RecordingLogger(),
            hooks: new ClientHooks(configEvaluated: function (ConfigEvaluatedEvent $event) use (&$evaluations): void {
                $evaluations[] = $event->evaluation;
            }),
        );
        $client->watch('day-of-the-week-config', 'Friday', static function (): void {});

        $client->initialize();

        self::assertEquals(
            [
                new ConfigEvaluation(
                    'day-of-the-week-config',
                    'Monday',
                    'value-monday',
                    null,
                    false,
                    EvaluationReason::FoundMatch,
                    null,
                ),
            ],
            $evaluations,
        );
    }

    public function testTheClientCallsConfigsUpdatedThenTheWatchesThenClientReady(): void
    {
        $calls = [];
        $client = ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            logger: new RecordingLogger(),
            hooks: new ClientHooks(
                clientReady: function () use (&$calls): void {
                    $calls[] = 'clientReady';
                },
                configsUpdated: function () use (&$calls): void {
                    $calls[] = 'configsUpdated';
                },
            ),
        );
        $client->watch('integer-config', 10, function () use (&$calls): void {
            $calls[] = 'watch';
        });

        $client->initialize();

        self::assertSame(['configsUpdated', 'watch', 'clientReady'], $calls);
    }

    public function testTheFunctionWatchReturnsRemovesOnlyThatWatch(): void
    {
        $days = [];
        $client = ConfigDirector::createClient(serverSdkKey: 'server-sdk-key', logger: new RecordingLogger());
        $recordDay = function (string $day) use (&$days): void {
            $days[] = $day;
        };
        $client->watch('day-of-the-week-config', 'Friday', $recordDay);
        $removeProPlanWatch = $client->watch(
            'day-of-the-week-config',
            'Friday',
            $recordDay,
            new Context(id: 'user-1', traits: ['plan' => 'pro']),
        );

        $removeProPlanWatch();
        $client->initialize();

        self::assertSame(['Monday'], $days);
    }

    public function testUnwatchWithACallbackRemovesTheWatchOnTheKeyWithThatCallback(): void
    {
        $calls = [];
        $client = ConfigDirector::createClient(serverSdkKey: 'server-sdk-key', logger: new RecordingLogger());
        $first = function (string $day) use (&$calls): void {
            $calls[] = "first {$day}";
        };
        $client->watch('day-of-the-week-config', 'Friday', $first);
        $client->watch('day-of-the-week-config', 'Friday', function (string $day) use (&$calls): void {
            $calls[] = "second {$day}";
        });

        $client->unwatch('day-of-the-week-config', $first);
        $client->initialize();

        self::assertSame(['second Monday'], $calls);
    }

    public function testUnwatchWithACallbackWatchingTwiceRemovesTheFirstWatchOnly(): void
    {
        $days = [];
        $client = ConfigDirector::createClient(serverSdkKey: 'server-sdk-key', logger: new RecordingLogger());
        $recordDay = function (string $day) use (&$days): void {
            $days[] = $day;
        };
        $client->watch('day-of-the-week-config', 'Friday', $recordDay);
        $client->watch(
            'day-of-the-week-config',
            'Friday',
            $recordDay,
            new Context(id: 'user-1', traits: ['plan' => 'pro']),
        );

        $client->unwatch('day-of-the-week-config', $recordDay);
        $client->initialize();

        self::assertSame(['Tuesday'], $days);
    }

    public function testUnwatchWithoutACallbackRemovesEveryWatchOnTheKey(): void
    {
        $calls = [];
        $client = ConfigDirector::createClient(serverSdkKey: 'server-sdk-key', logger: new RecordingLogger());
        $client->watch('day-of-the-week-config', 'Friday', function (string $day) use (&$calls): void {
            $calls[] = "first {$day}";
        });
        $client->watch('day-of-the-week-config', 'Friday', function (string $day) use (&$calls): void {
            $calls[] = "second {$day}";
        });
        $client->watch('integer-config', 10, function (int $number) use (&$calls): void {
            $calls[] = "integer {$number}";
        });

        $client->unwatch('day-of-the-week-config');
        $client->initialize();

        self::assertSame(['integer 42'], $calls);
    }

    public function testUnwatchAllRemovesEveryWatch(): void
    {
        $calls = [];
        $client = ConfigDirector::createClient(serverSdkKey: 'server-sdk-key', logger: new RecordingLogger());
        $client->watch('day-of-the-week-config', 'Friday', function (string $day) use (&$calls): void {
            $calls[] = $day;
        });
        $client->watch('integer-config', 10, function (int $number) use (&$calls): void {
            $calls[] = $number;
        });

        $client->unwatchAll();
        $client->initialize();

        self::assertSame([], $calls);
    }

    public function testAWatchThatThrowsIsLoggedOnceAndTheOtherWatchesStillReceiveTheirValue(): void
    {
        $logger = new RecordingLogger();
        $exception = new \RuntimeException('The watch failed');
        $days = [];
        $client = ConfigDirector::createClient(serverSdkKey: 'server-sdk-key', logger: $logger);
        $client->watch('integer-config', 10, static function () use ($exception): void {
            throw $exception;
        });
        $client->watch('day-of-the-week-config', 'Friday', function (string $day) use (&$days): void {
            $days[] = $day;
        });

        $client->initialize();

        self::assertSame(['Monday'], $days);
        self::assertTrue($client->isReady());
        self::assertSame(
            [['configKey' => 'integer-config', 'exception' => $exception]],
            $logger->contextsLoggedAt(LogLevel::ERROR),
        );
    }
}
