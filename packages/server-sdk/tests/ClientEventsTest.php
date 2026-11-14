<?php

declare(strict_types=1);

namespace ConfigDirector\Tests;

use ConfigDirector\ClientHooks;
use ConfigDirector\ConfigDirector;
use ConfigDirector\ConfigDirectorValidationException;
use ConfigDirector\ConfigEvaluatedEvent;
use ConfigDirector\ConfigEvaluation;
use ConfigDirector\ConfigsUpdatedEvent;
use ConfigDirector\EvaluationReason;
use ConfigDirector\Tests\Support\RecordingLogger;
use ConfigDirector\Tests\Support\SampleConfigsTestCase;
use PHPUnit\Framework\Attributes\DoesNotPerformAssertions;
use Psr\Log\LogLevel;

final class ClientEventsTest extends SampleConfigsTestCase
{
    public function testAHookReceivesClientReadyDuringInitialize(): void
    {
        $readyCount = 0;
        $client = ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            connection: self::connectionToTheSdkServer(),
            logger: new RecordingLogger(),
            hooks: new ClientHooks(clientReady: static function () use (&$readyCount): void {
                $readyCount++;
            }),
        );

        $client->initialize();

        self::assertSame(1, $readyCount);
    }

    public function testAnOnHandlerReceivesClientReadyDuringInitialize(): void
    {
        $readyCount = 0;
        $client = ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            connection: self::connectionToTheSdkServer(),
            logger: new RecordingLogger(),
        );
        $client->on('clientReady', static function () use (&$readyCount): void {
            $readyCount++;
        });

        $client->initialize();

        self::assertSame(1, $readyCount);
    }

    public function testAClientReadyHandlerReceivesNoArgument(): void
    {
        $argumentCounts = [];
        $client = ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            connection: self::connectionToTheSdkServer(),
            logger: new RecordingLogger(),
        );
        $client->on('clientReady', static function () use (&$argumentCounts): void {
            $argumentCounts[] = func_num_args();
        });

        $client->initialize();

        self::assertSame([0], $argumentCounts);
    }

    public function testClientReadyFiresOnlyWhenTheClientBecomesReady(): void
    {
        $readyCount = 0;
        $client = ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            connection: self::connectionToTheSdkServer(),
            logger: new RecordingLogger(),
        );
        $client->on('clientReady', static function () use (&$readyCount): void {
            $readyCount++;
        });
        $client->initialize();

        $client->initialize();

        self::assertSame(1, $readyCount);
    }

    public function testAConfigsUpdatedHandlerReadsTheValuesReceived(): void
    {
        $valuesRead = [];
        $client = ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            connection: self::connectionToTheSdkServer(),
            logger: new RecordingLogger(),
        );
        $client->on('configsUpdated', static function () use ($client, &$valuesRead): void {
            $valuesRead[] = $client->getValue('integer-config', 10);
        });

        $client->initialize();

        self::assertSame([42], $valuesRead);
    }

    public function testOffWithAHandlerRemovesOnlyThatHandler(): void
    {
        $calls = [];
        $client = ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            connection: self::connectionToTheSdkServer(),
            logger: new RecordingLogger(),
        );
        $first = static function () use (&$calls): void {
            $calls[] = 'first';
        };
        $client->on('clientReady', $first);
        $client->on('clientReady', static function () use (&$calls): void {
            $calls[] = 'second';
        });

        $client->off('clientReady', $first);
        $client->initialize();

        self::assertSame(['second'], $calls);
    }

    public function testOffRemovesOneRegistrationOfAHandlerRegisteredTwice(): void
    {
        $readyCount = 0;
        $client = ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            connection: self::connectionToTheSdkServer(),
            logger: new RecordingLogger(),
        );
        $countReady = static function () use (&$readyCount): void {
            $readyCount++;
        };
        $client->on('clientReady', $countReady);
        $client->on('clientReady', $countReady);

        $client->off('clientReady', $countReady);
        $client->initialize();

        self::assertSame(1, $readyCount);
    }

    public function testOffWithoutAHandlerRemovesEveryHandlerOfTheEvent(): void
    {
        $calls = [];
        $client = ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            connection: self::connectionToTheSdkServer(),
            logger: new RecordingLogger(),
        );
        $client->on('clientReady', static function () use (&$calls): void {
            $calls[] = 'first clientReady';
        });
        $client->on('clientReady', static function () use (&$calls): void {
            $calls[] = 'second clientReady';
        });
        $client->on('configsUpdated', static function () use (&$calls): void {
            $calls[] = 'configsUpdated';
        });

        $client->off('clientReady');
        $client->initialize();

        self::assertSame(['configsUpdated'], $calls);
    }

    public function testAnObjectMethodHookIsOneHandler(): void
    {
        $recorder = new class {
            public int $readyCount = 0;

            public function recordReady(): void
            {
                $this->readyCount++;
            }
        };
        $client = ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            connection: self::connectionToTheSdkServer(),
            logger: new RecordingLogger(),
            hooks: new ClientHooks(clientReady: [$recorder, 'recordReady']),
        );

        $client->initialize();

        self::assertSame(1, $recorder->readyCount);
    }

    public function testAHookThatIsNotCallableIsIgnoredWithAWarning(): void
    {
        $logger = new RecordingLogger();
        $readyCount = 0;
        $client = ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            connection: self::connectionToTheSdkServer(),
            logger: $logger,
            hooks: new ClientHooks(
                // @phpstan-ignore argument.type
                clientReady: [
                    'not a function',
                    static function () use (&$readyCount): void {
                        $readyCount++;
                    },
                ],
            ),
        );

        $client->initialize();

        self::assertSame(1, $readyCount);
        self::assertSame(
            [['eventName' => 'clientReady', 'hookType' => 'string']],
            $logger->contextsLoggedAt(LogLevel::WARNING),
        );
    }

    #[DoesNotPerformAssertions]
    public function testOnAcceptsConnectionError(): void
    {
        $client = ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            connection: self::connectionToTheSdkServer(),
            logger: new RecordingLogger(),
        );

        $client->on('connectionError', static function (): void {});
    }

    public function testOnRejectsAnUnknownEventName(): void
    {
        $client = ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            connection: self::connectionToTheSdkServer(),
            logger: new RecordingLogger(),
        );

        $this->expectException(ConfigDirectorValidationException::class);

        // @phpstan-ignore argument.type
        $client->on('configUpdated', static function (): void {});
    }

    public function testOffRejectsAnUnknownEventName(): void
    {
        $client = ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            connection: self::connectionToTheSdkServer(),
            logger: new RecordingLogger(),
        );

        $this->expectException(ConfigDirectorValidationException::class);

        // @phpstan-ignore argument.type
        $client->off('configUpdated');
    }

    public function testConfigsUpdatedNamesEveryConfigKeyReceivedDuringInitialize(): void
    {
        $updates = [];
        $client = ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            connection: self::connectionToTheSdkServer(),
            logger: new RecordingLogger(),
            hooks: new ClientHooks(configsUpdated: static function (ConfigsUpdatedEvent $update) use (&$updates): void {
                $updates[] = $update;
            }),
        );

        $client->initialize();

        self::assertEquals(
            [
                new ConfigsUpdatedEvent(
                    [
                        'temporary-feature-flag',
                        'permanent-kill-switch',
                        'integer-config',
                        'day-of-the-week-config',
                        'json-value-config',
                        'float-config',
                        'config-without-a-value',
                        'malformed-json-config',
                    ],
                    [],
                ),
            ],
            $updates,
        );
    }

    public function testAConfigEvaluatedHandlerThatReadsAConfigIsNotCalledForItsOwnRead(): void
    {
        $keysTheHandlerWasCalledFor = [];
        $valuesTheHandlerRead = [];
        $client = ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            connection: self::connectionToTheSdkServer(),
            logger: new RecordingLogger(),
        );
        $client->initialize();
        $client->on(
            'configEvaluated',
            static function (ConfigEvaluatedEvent $event) use (
                $client,
                &$keysTheHandlerWasCalledFor,
                &$valuesTheHandlerRead,
            ): void {
                $keysTheHandlerWasCalledFor[] = $event->evaluation->key;
                $valuesTheHandlerRead[] = $client->getValue('day-of-the-week-config', 'Friday');
            },
        );

        $value = $client->getValue('integer-config', 10);

        self::assertSame(42, $value);
        self::assertSame(['integer-config'], $keysTheHandlerWasCalledFor);
        self::assertSame(['Monday'], $valuesTheHandlerRead);
    }

    public function testAReadAfterAHandlerWhoseOwnReadWasRejectedStillCallsTheHandler(): void
    {
        $keysTheHandlerWasCalledFor = [];
        $client = ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            connection: self::connectionToTheSdkServer(),
            logger: new RecordingLogger(),
        );
        $client->initialize();
        $client->on(
            'configEvaluated',
            static function (ConfigEvaluatedEvent $event) use ($client, &$keysTheHandlerWasCalledFor): void {
                $keysTheHandlerWasCalledFor[] = $event->evaluation->key;
                // @phpstan-ignore argument.type
                $client->getValue('day-of-the-week-config', null);
            },
        );

        $client->getValue('integer-config', 10);
        $client->getValue('integer-config', 10);

        self::assertSame(['integer-config', 'integer-config'], $keysTheHandlerWasCalledFor);
    }

    public function testConfigEvaluatedCarriesTheEvaluationOfARead(): void
    {
        $evaluations = [];
        $client = ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            connection: self::connectionToTheSdkServer(),
            logger: new RecordingLogger(),
            hooks: new ClientHooks(
                configEvaluated: static function (ConfigEvaluatedEvent $event) use (&$evaluations): void {
                    $evaluations[] = $event->evaluation;
                },
            ),
        );
        $client->initialize();

        $client->getValue('integer-config', 10);

        self::assertEquals(
            [
                new ConfigEvaluation(
                    key: 'integer-config',
                    value: 42,
                    valueId: 'value-integer-42',
                    variation: null,
                    isDefaultValue: false,
                    reason: EvaluationReason::FoundMatch,
                    context: null,
                ),
            ],
            $evaluations,
        );
    }

    public function testConfigEvaluatedCarriesTheEvaluationEvaluateReturns(): void
    {
        $evaluations = [];
        $client = ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            connection: self::connectionToTheSdkServer(),
            logger: new RecordingLogger(),
            hooks: new ClientHooks(
                configEvaluated: static function (ConfigEvaluatedEvent $event) use (&$evaluations): void {
                    $evaluations[] = $event->evaluation;
                },
            ),
        );
        $client->initialize();

        $evaluation = $client->evaluate('day-of-the-week-config', 'Friday');

        self::assertSame([$evaluation], $evaluations);
    }

    public function testConfigEvaluatedCarriesAnEvaluationThatReturnedTheInCodeDefaultValue(): void
    {
        $evaluations = [];
        $client = ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            connection: self::connectionToTheSdkServer(),
            logger: new RecordingLogger(),
            hooks: new ClientHooks(
                configEvaluated: static function (ConfigEvaluatedEvent $event) use (&$evaluations): void {
                    $evaluations[] = $event->evaluation;
                },
            ),
        );
        $client->initialize();

        $client->getValue('missing-config', 10);

        self::assertEquals(
            [new ConfigEvaluation(
                key: 'missing-config',
                value: 10,
                valueId: null,
                variation: null,
                isDefaultValue: true,
                reason: EvaluationReason::ConfigStateMissing,
                context: null,
            )],
            $evaluations,
        );
    }

    public function testGetAllConfigsFiresNoConfigEvaluated(): void
    {
        $evaluations = [];
        $client = ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            connection: self::connectionToTheSdkServer(),
            logger: new RecordingLogger(),
            hooks: new ClientHooks(
                configEvaluated: static function (ConfigEvaluatedEvent $event) use (&$evaluations): void {
                    $evaluations[] = $event->evaluation;
                },
            ),
        );
        $client->initialize();

        $client->getAllConfigs();

        self::assertSame([], $evaluations);
    }

    public function testAHandlerThatThrowsIsLoggedOnceAndTheReadStillReturnsItsValue(): void
    {
        $logger = new RecordingLogger();
        $exception = new \RuntimeException('The handler failed');
        $client = ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            connection: self::connectionToTheSdkServer(),
            logger: $logger,
            hooks: new ClientHooks(configEvaluated: static function () use ($exception): void {
                throw $exception;
            }),
        );
        $client->initialize();

        $value = $client->getValue('integer-config', 10);

        self::assertSame(42, $value);
        self::assertSame(
            [['eventName' => 'configEvaluated', 'exception' => $exception]],
            $logger->contextsLoggedAt(LogLevel::ERROR),
        );
    }

    public function testTheOtherHandlersAndTheDeliveryGoOnAfterAHandlerThrows(): void
    {
        $calls = [];
        $client = ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            connection: self::connectionToTheSdkServer(),
            logger: new RecordingLogger(),
            hooks: new ClientHooks(
                clientReady: static function () use (&$calls): void {
                    $calls[] = 'clientReady';
                },
                configsUpdated: [
                    static function (): void {
                        throw new \RuntimeException('The handler failed');
                    },
                    static function () use (&$calls): void {
                        $calls[] = 'configsUpdated';
                    },
                ],
            ),
        );

        $client->initialize();

        self::assertSame(['configsUpdated', 'clientReady'], $calls);
        self::assertTrue($client->isReady());
    }

    public function testDisposeRemovesEveryHandler(): void
    {
        $evaluations = [];
        $client = ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            connection: self::connectionToTheSdkServer(),
            logger: new RecordingLogger(),
            hooks: new ClientHooks(
                configEvaluated: static function (ConfigEvaluatedEvent $event) use (&$evaluations): void {
                    $evaluations[] = $event->evaluation;
                },
            ),
        );
        $client->on('configEvaluated', static function (ConfigEvaluatedEvent $event) use (&$evaluations): void {
            $evaluations[] = $event->evaluation;
        });
        $client->initialize();

        $client->dispose();
        $client->getValue('integer-config', 10);

        self::assertSame([], $evaluations);
    }
}
