<?php

declare(strict_types=1);

namespace ConfigDirector\Tests;

use ConfigDirector\ConfigDirector;
use ConfigDirector\ConfigEvaluation;
use ConfigDirector\Context;
use ConfigDirector\EvaluationReason;
use ConfigDirector\Tests\Support\RecordingLogger;
use ConfigDirector\Tests\Support\SampleConfigsTestCase;
use Psr\Log\LogLevel;

final class ClientContextKindTest extends SampleConfigsTestCase
{
    public function testAContextOfACustomKindIsEvaluated(): void
    {
        $logger = new RecordingLogger();
        $client = ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            connection: self::connectionToTheSdkServer(),
            logger: $logger,
        );
        $client->initialize();
        $proDevice = new Context(id: 'device-1', kind: 'device', traits: ['plan' => 'pro']);

        $evaluation = $client->evaluate('day-of-the-week-config', 'Friday', $proDevice);

        self::assertEquals(
            new ConfigEvaluation(
                'day-of-the-week-config',
                'Tuesday',
                'value-tuesday',
                null,
                false,
                EvaluationReason::FoundMatch,
                $proDevice,
            ),
            $evaluation,
        );
        self::assertSame([], $logger->contextsLoggedAt(LogLevel::ERROR));
    }

    public function testAContextWithAnInvalidKindIsIgnoredWhole(): void
    {
        $logger = new RecordingLogger();
        $client = ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            connection: self::connectionToTheSdkServer(),
            logger: $logger,
        );
        $client->initialize();

        $evaluation = $client->evaluate(
            'day-of-the-week-config',
            'Friday',
            new Context(id: 'device-1', kind: 'Device', traits: ['plan' => 'pro']),
        );

        self::assertEquals(
            new ConfigEvaluation(
                'day-of-the-week-config',
                'Monday',
                'value-monday',
                null,
                false,
                EvaluationReason::FoundMatch,
                null,
            ),
            $evaluation,
        );
    }

    public function testAContextWithAnInvalidKindLogsOneErrorNamingTheKind(): void
    {
        $logger = new RecordingLogger();
        $client = ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            connection: self::connectionToTheSdkServer(),
            logger: $logger,
        );
        $client->initialize();

        $client->getValue('day-of-the-week-config', 'Friday', new Context(id: 'device-1', kind: 'Device'));

        self::assertSame([['kindSample' => 'Device']], $logger->contextsLoggedAt(LogLevel::ERROR));
    }

    public function testAnInCodeDefaultValueReturnedForAnIgnoredContextCarriesNoContext(): void
    {
        $client = ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            connection: self::connectionToTheSdkServer(),
            logger: new RecordingLogger(),
        );
        $client->initialize();

        $evaluation = $client->evaluate('no-such-config', 'fallback', new Context(id: 'device-1', kind: 'Device'));

        self::assertEquals(
            new ConfigEvaluation(
                'no-such-config',
                'fallback',
                null,
                null,
                true,
                EvaluationReason::ConfigStateMissing,
                null,
            ),
            $evaluation,
        );
    }

    public function testAnIgnoredContextSkipsThePercentageRollout(): void
    {
        $client = ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            connection: self::connectionToTheSdkServer(),
            logger: new RecordingLogger(),
        );
        $client->initialize();

        $evaluation = $client->evaluate('temporary-feature-flag', true, new Context(id: 'device-1', kind: 'Device'));

        self::assertFalse($evaluation->value);
        self::assertSame(EvaluationReason::RolloutSkippedNoIdentifier, $evaluation->reason);
    }

    public function testAnEmptyKindIsInvalid(): void
    {
        $logger = new RecordingLogger();
        $client = ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            connection: self::connectionToTheSdkServer(),
            logger: $logger,
        );
        $client->initialize();

        $evaluation = $client->evaluate('integer-config', 10, new Context(id: 'user-123', kind: ''));

        self::assertNull($evaluation->context);
        self::assertSame([['kindSample' => '']], $logger->contextsLoggedAt(LogLevel::ERROR));
    }

    public function testAKindOfSixtyFourCharactersIsValid(): void
    {
        $logger = new RecordingLogger();
        $client = ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            connection: self::connectionToTheSdkServer(),
            logger: $logger,
        );
        $client->initialize();
        $context = new Context(id: 'tenant-1', kind: str_repeat('k', 64));

        $evaluation = $client->evaluate('integer-config', 10, $context);

        self::assertSame($context, $evaluation->context);
        self::assertSame([], $logger->contextsLoggedAt(LogLevel::ERROR));
    }

    public function testAKindOfSixtyFiveCharactersIsInvalid(): void
    {
        $client = ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            connection: self::connectionToTheSdkServer(),
            logger: new RecordingLogger(),
        );
        $client->initialize();
        $context = new Context(id: 'tenant-1', kind: str_repeat('k', 65));

        $evaluation = $client->evaluate('integer-config', 10, $context);

        self::assertNull($evaluation->context);
    }

    public function testTheSameInvalidKindIsLoggedOnce(): void
    {
        $logger = new RecordingLogger();
        $client = ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            connection: self::connectionToTheSdkServer(),
            logger: $logger,
        );
        $client->initialize();

        $client->getValue('integer-config', 10, new Context(id: 'device-1', kind: 'Device'));
        $client->getValue('integer-config', 10, new Context(id: 'device-2', kind: 'Device'));

        self::assertSame([['kindSample' => 'Device']], $logger->contextsLoggedAt(LogLevel::ERROR));
    }

    public function testEachDifferentInvalidKindIsLogged(): void
    {
        $logger = new RecordingLogger();
        $client = ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            connection: self::connectionToTheSdkServer(),
            logger: $logger,
        );
        $client->initialize();

        $client->getValue('integer-config', 10, new Context(id: 'device-1', kind: 'Device'));
        $client->getValue('integer-config', 10, new Context(id: 'tenant-1', kind: 'Tenant'));

        self::assertSame(
            [['kindSample' => 'Device'], ['kindSample' => 'Tenant']],
            $logger->contextsLoggedAt(LogLevel::ERROR),
        );
    }

    public function testInvalidKindsBeyondTwentyAreLoggedOnceTogether(): void
    {
        $logger = new RecordingLogger();
        $client = ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            connection: self::connectionToTheSdkServer(),
            logger: $logger,
        );
        $client->initialize();
        foreach (range(1, 20) as $number) {
            $client->getValue('integer-config', 10, new Context(kind: 'Kind' . $number));
        }

        $client->getValue('integer-config', 10, new Context(kind: 'Kind21'));
        $client->getValue('integer-config', 10, new Context(kind: 'Kind22'));

        $errors = $logger->contextsLoggedAt(LogLevel::ERROR);
        self::assertCount(21, $errors);
        self::assertSame(['kindSample' => 'Kind20'], $errors[19]);
        self::assertSame(['sampleLimit' => 20], $errors[20]);
    }

    public function testTheErrorNamesOnlyTheFirstCharactersOfAVeryLongKind(): void
    {
        $logger = new RecordingLogger();
        $client = ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            connection: self::connectionToTheSdkServer(),
            logger: $logger,
        );
        $client->initialize();

        $client->getValue('integer-config', 10, new Context(kind: str_repeat('é', 200)));

        self::assertSame([['kindSample' => str_repeat('é', 128)]], $logger->contextsLoggedAt(LogLevel::ERROR));
    }
}
