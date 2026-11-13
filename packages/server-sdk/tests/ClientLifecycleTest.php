<?php

declare(strict_types=1);

namespace ConfigDirector\Tests;

use ConfigDirector\ConfigDirector;
use ConfigDirector\ConfigEvaluation;
use ConfigDirector\EvaluationReason;
use ConfigDirector\Tests\Support\RecordingLogger;
use ConfigDirector\Tests\Support\SampleConfigsTestCase;
use Psr\Log\LogLevel;

final class ClientLifecycleTest extends SampleConfigsTestCase
{
    public function testANewClientIsNotReady(): void
    {
        $client = ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            connection: self::connectionToTheSdkServer(),
            logger: new RecordingLogger(),
        );

        self::assertFalse($client->isReady());
    }

    public function testAnInitializedClientIsReady(): void
    {
        $client = ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            connection: self::connectionToTheSdkServer(),
            logger: new RecordingLogger(),
        );

        $client->initialize();

        self::assertTrue($client->isReady());
    }

    public function testInitializeLogsNoWarningWhenTheValuesArrive(): void
    {
        $logger = new RecordingLogger();
        $client = ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            connection: self::connectionToTheSdkServer(),
            logger: $logger,
        );

        $client->initialize();

        self::assertSame([], $logger->contextsLoggedAt(LogLevel::WARNING));
    }

    public function testADisposedClientIsNotReady(): void
    {
        $client = ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            connection: self::connectionToTheSdkServer(),
            logger: new RecordingLogger(),
        );
        $client->initialize();

        $client->dispose();

        self::assertFalse($client->isReady());
    }

    public function testAReadAfterDisposeReturnsTheInCodeDefaultValue(): void
    {
        $client = ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            connection: self::connectionToTheSdkServer(),
            logger: new RecordingLogger(),
        );
        $client->initialize();
        $client->dispose();

        $evaluation = $client->evaluate('integer-config', 10);

        self::assertEquals(
            new ConfigEvaluation('integer-config', 10, null, null, true, EvaluationReason::ClientNotReady, null),
            $evaluation,
        );
    }

    public function testNoConfigStatesAreReturnedAfterDispose(): void
    {
        $client = ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            connection: self::connectionToTheSdkServer(),
            logger: new RecordingLogger(),
        );
        $client->initialize();
        $client->dispose();

        $states = $client->getAllConfigs();

        self::assertSame([], $states);
    }

    public function testDisposingTwiceIsHarmless(): void
    {
        $client = ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            connection: self::connectionToTheSdkServer(),
            logger: new RecordingLogger(),
        );
        $client->initialize();
        $client->dispose();

        $client->dispose();

        self::assertFalse($client->isReady());
        self::assertSame(10, $client->getValue('integer-config', 10));
    }

    public function testDisposingAClientThatWasNeverInitializedIsHarmless(): void
    {
        $client = ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            connection: self::connectionToTheSdkServer(),
            logger: new RecordingLogger(),
        );

        $client->dispose();

        self::assertFalse($client->isReady());
    }

    public function testADisposedClientStaysNotReadyWhenInitializedAgain(): void
    {
        $logger = new RecordingLogger();
        $client = ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            connection: self::connectionToTheSdkServer(),
            logger: $logger,
        );
        $client->initialize();
        $client->dispose();

        $client->initialize();

        self::assertFalse($client->isReady());
        self::assertSame(10, $client->getValue('integer-config', 10));
        self::assertCount(1, $logger->contextsLoggedAt(LogLevel::WARNING));
    }
}
