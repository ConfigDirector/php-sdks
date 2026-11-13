<?php

declare(strict_types=1);

namespace ConfigDirector\Tests;

use ConfigDirector\ConfigDirector;
use ConfigDirector\ConfigDirectorClient;
use ConfigDirector\ConfigState;
use ConfigDirector\Context;
use ConfigDirector\Tests\Support\RecordingLogger;
use ConfigDirector\Tests\Support\SampleConfigsTestCase;
use ConfigDirector\Variation;
use Psr\Log\LogLevel;

final class ClientAllConfigsTest extends SampleConfigsTestCase
{
    public function testEveryConfigIsReturnedUnderItsKey(): void
    {
        $client = self::readyClient();

        $states = $client->getAllConfigs();

        self::assertSame(
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
            array_keys($states),
        );
    }

    public function testAConfigStateCarriesTheServedTextAndItsValueId(): void
    {
        $client = self::readyClient();

        $states = $client->getAllConfigs();

        self::assertEquals(
            new ConfigState(
                '9a3e5b71-6c2d-4f08-8b1a-4d7c2e9f5a03',
                'integer-config',
                'integer',
                '42',
                'value-integer-42',
            ),
            $states['integer-config'],
        );
    }

    public function testConfigStatesAreEvaluatedForTheContext(): void
    {
        $client = self::readyClient();

        $states = $client->getAllConfigs(context: new Context(id: 'user-123', traits: ['plan' => 'pro']));

        self::assertEquals(
            new ConfigState(
                '5c8a2d94-1b7f-4e63-a0d5-9f3b6c1e7d04',
                'day-of-the-week-config',
                'string',
                'Tuesday',
                'value-tuesday',
            ),
            $states['day-of-the-week-config'],
        );
    }

    public function testAConfigStateServedByARolloutCarriesItsVariationRuleAndShare(): void
    {
        $client = self::readyClient();

        $states = $client->getAllConfigs(context: new Context(id: 'user-123'));

        self::assertEquals(
            new ConfigState(
                '7b0c4f52-3d1e-4a86-9c2f-1e5d8a6b4c01',
                'temporary-feature-flag',
                'boolean',
                'true',
                'value-flag-on',
                new Variation('variation-flag-on', 'On'),
                'rule-flag-rollout',
                'share-flag-on',
            ),
            $states['temporary-feature-flag'],
        );
    }

    public function testOnlyTheListedConfigKeysAreReturned(): void
    {
        $client = self::readyClient();

        $states = $client->getAllConfigs(configKeys: ['float-config', 'integer-config']);

        self::assertSame(['integer-config', 'float-config'], array_keys($states));
    }

    public function testAListedConfigKeyThatDoesNotExistIsLeftOut(): void
    {
        $client = self::readyClient();

        $states = $client->getAllConfigs(configKeys: ['integer-config', 'no-such-config']);

        self::assertSame(['integer-config'], array_keys($states));
    }

    public function testAnEmptyListOfConfigKeysReturnsNothing(): void
    {
        $client = self::readyClient();

        $states = $client->getAllConfigs(configKeys: []);

        self::assertSame([], $states);
    }

    public function testNothingIsReturnedBeforeInitialize(): void
    {
        $client = ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            connection: self::connectionToTheSdkServer(),
            logger: new RecordingLogger(),
        );

        $states = $client->getAllConfigs();

        self::assertSame([], $states);
    }

    public function testAContextWithAnInvalidKindIsIgnoredWholeAndLogged(): void
    {
        $logger = new RecordingLogger();
        $client = ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            connection: self::connectionToTheSdkServer(),
            logger: $logger,
        );
        $client->initialize();
        $invalidContext = new Context(id: 'device-1', kind: 'Device', traits: ['plan' => 'pro']);

        $states = $client->getAllConfigs(context: $invalidContext);

        self::assertSame('Monday', $states['day-of-the-week-config']->value);
        self::assertSame([['kindSample' => 'Device']], $logger->contextsLoggedAt(LogLevel::ERROR));
    }

    private static function readyClient(): ConfigDirectorClient
    {
        $client = ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            connection: self::connectionToTheSdkServer(),
            logger: new RecordingLogger(),
        );
        $client->initialize();

        return $client;
    }
}
