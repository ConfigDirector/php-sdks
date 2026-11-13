<?php

declare(strict_types=1);

namespace ConfigDirector\Tests;

use ConfigDirector\ConfigDirector;
use ConfigDirector\ConfigDirectorClient;
use ConfigDirector\ConfigDirectorValidationException;
use ConfigDirector\ConfigEvaluation;
use ConfigDirector\Context;
use ConfigDirector\EvaluationReason;
use ConfigDirector\Metadata;
use ConfigDirector\Tests\Support\RecordingLogger;
use ConfigDirector\Tests\Support\SampleConfigsTestCase;
use ConfigDirector\Variation;

final class ClientReadsTest extends SampleConfigsTestCase
{
    public function testABooleanConfigReturnsTheServedBoolean(): void
    {
        $client = self::readyClient();

        $evaluation = $client->evaluate('permanent-kill-switch', false);

        self::assertEquals(
            new ConfigEvaluation(
                'permanent-kill-switch',
                true,
                'value-kill-switch-on',
                null,
                false,
                EvaluationReason::FoundMatch,
                null,
            ),
            $evaluation,
        );
    }

    public function testAStringConfigReturnsTheServedString(): void
    {
        $client = self::readyClient();

        $evaluation = $client->evaluate('day-of-the-week-config', 'Friday');

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

    public function testAnIntegerConfigReturnsTheServedInteger(): void
    {
        $client = self::readyClient();

        $evaluation = $client->evaluate('integer-config', 10);

        self::assertSame(42, $evaluation->value);
        self::assertSame(EvaluationReason::FoundMatch, $evaluation->reason);
        self::assertFalse($evaluation->isDefaultValue);
    }

    public function testAFloatConfigReturnsTheServedFloat(): void
    {
        $client = self::readyClient();

        $evaluation = $client->evaluate('float-config', 1.5);

        self::assertSame(26.0, $evaluation->value);
        self::assertSame(EvaluationReason::FoundMatch, $evaluation->reason);
        self::assertFalse($evaluation->isDefaultValue);
    }

    public function testAJsonConfigReturnsAPhpArray(): void
    {
        $client = self::readyClient();

        $evaluation = $client->evaluate('json-value-config', []);

        self::assertSame(['greeting' => 'hello', 'sizes' => [1, 2]], $evaluation->value);
        self::assertSame(EvaluationReason::FoundMatch, $evaluation->reason);
        self::assertFalse($evaluation->isDefaultValue);
    }

    public function testAnIntegerReadAcceptsAWholeNumberWrittenWithADecimalPoint(): void
    {
        $client = self::readyClient();

        $evaluation = $client->evaluate('float-config', 10);

        self::assertSame(26, $evaluation->value);
        self::assertSame(EvaluationReason::FoundMatch, $evaluation->reason);
    }

    public function testAnArrayReadWithAListInCodeDefaultValueAcceptsAJsonObject(): void
    {
        $client = self::readyClient();

        $value = $client->getValue('json-value-config', ['first', 'second']);

        self::assertSame(['greeting' => 'hello', 'sizes' => [1, 2]], $value);
    }

    public function testAStringReadOfAJsonConfigReturnsItsText(): void
    {
        $client = self::readyClient();

        $value = $client->getValue('json-value-config', 'fallback');

        self::assertSame('{"greeting":"hello","sizes":[1,2]}', $value);
    }

    public function testGetValueReturnsTheValueOfTheEvaluation(): void
    {
        $client = self::readyClient();

        $value = $client->getValue('integer-config', 10);

        self::assertSame(42, $value);
    }

    public function testARuleServesItsValueToTheContextItTargets(): void
    {
        $client = self::readyClient();
        $proCustomer = new Context(id: 'user-123', name: 'Ada', traits: ['plan' => 'pro']);

        $evaluation = $client->evaluate('day-of-the-week-config', 'Friday', $proCustomer);

        self::assertEquals(
            new ConfigEvaluation(
                'day-of-the-week-config',
                'Tuesday',
                'value-tuesday',
                null,
                false,
                EvaluationReason::FoundMatch,
                $proCustomer,
            ),
            $evaluation,
        );
    }

    public function testARuleOnTheApplicationNameServesItsValueToThatApplication(): void
    {
        $client = ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            connection: self::connectionToTheSdkServer(),
            metadata: new Metadata(appName: 'beta-app'),
            logger: new RecordingLogger(),
        );
        $client->initialize();

        $value = $client->getValue('integer-config', 10);

        self::assertSame(7, $value);
    }

    public function testARolloutServesItsShareAndVariationToAContextWithAnIdentifier(): void
    {
        $client = self::readyClient();
        $customer = new Context(id: 'user-123');

        $evaluation = $client->evaluate('temporary-feature-flag', false, $customer);

        self::assertEquals(
            new ConfigEvaluation(
                'temporary-feature-flag',
                true,
                'value-flag-on',
                new Variation('variation-flag-on', 'On'),
                false,
                EvaluationReason::FoundMatch,
                $customer,
            ),
            $evaluation,
        );
    }

    public function testASkippedRolloutReturnsTheDefaultTargetingRuleValue(): void
    {
        $client = self::readyClient();

        $evaluation = $client->evaluate('temporary-feature-flag', true);

        self::assertEquals(
            new ConfigEvaluation(
                'temporary-feature-flag',
                false,
                'value-flag-off',
                new Variation('variation-flag-off', 'Off'),
                false,
                EvaluationReason::RolloutSkippedNoIdentifier,
                null,
            ),
            $evaluation,
        );
    }

    public function testAnUnknownConfigKeyReturnsTheInCodeDefaultValue(): void
    {
        $client = self::readyClient();

        $evaluation = $client->evaluate('no-such-config', 'fallback');

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

    public function testABlankConfigKeyReturnsTheInCodeDefaultValue(): void
    {
        $client = self::readyClient();

        $evaluation = $client->evaluate('', 'fallback');

        self::assertEquals(
            new ConfigEvaluation('', 'fallback', null, null, true, EvaluationReason::ConfigStateMissing, null),
            $evaluation,
        );
    }

    public function testAReadBeforeInitializeReturnsTheInCodeDefaultValue(): void
    {
        $client = ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            connection: self::connectionToTheSdkServer(),
            logger: new RecordingLogger(),
        );

        $evaluation = $client->evaluate('integer-config', 10);

        self::assertEquals(
            new ConfigEvaluation('integer-config', 10, null, null, true, EvaluationReason::ClientNotReady, null),
            $evaluation,
        );
    }

    public function testAStringReadOfABooleanConfigReturnsTheInCodeDefaultValue(): void
    {
        $client = self::readyClient();

        $evaluation = $client->evaluate('permanent-kill-switch', 'fallback');

        self::assertEquals(
            new ConfigEvaluation(
                'permanent-kill-switch',
                'fallback',
                null,
                null,
                true,
                EvaluationReason::TypeMismatch,
                null,
            ),
            $evaluation,
        );
    }

    public function testAConfigWithoutAValueReturnsTheInCodeDefaultValue(): void
    {
        $client = self::readyClient();

        $evaluation = $client->evaluate('config-without-a-value', 'fallback');

        self::assertEquals(
            new ConfigEvaluation(
                'config-without-a-value',
                'fallback',
                null,
                null,
                true,
                EvaluationReason::ValueMissing,
                null,
            ),
            $evaluation,
        );
    }

    public function testAnIntegerReadOfTextThatIsNotANumberReturnsTheInCodeDefaultValue(): void
    {
        $client = self::readyClient();

        $evaluation = $client->evaluate('day-of-the-week-config', 10);

        self::assertEquals(
            new ConfigEvaluation('day-of-the-week-config', 10, null, null, true, EvaluationReason::InvalidNumber, null),
            $evaluation,
        );
    }

    public function testAnArrayReadOfMalformedJsonReturnsTheInCodeDefaultValue(): void
    {
        $client = self::readyClient();

        $evaluation = $client->evaluate('malformed-json-config', ['greeting' => 'fallback']);

        self::assertEquals(
            new ConfigEvaluation(
                'malformed-json-config',
                ['greeting' => 'fallback'],
                null,
                null,
                true,
                EvaluationReason::InvalidJson,
                null,
            ),
            $evaluation,
        );
    }

    public function testABooleanReadOfTextThatIsNeitherTrueNorFalseReturnsTheInCodeDefaultValue(): void
    {
        $client = self::readyClient();

        $evaluation = $client->evaluate('day-of-the-week-config', true);

        self::assertEquals(
            new ConfigEvaluation(
                'day-of-the-week-config',
                true,
                null,
                null,
                true,
                EvaluationReason::InvalidBoolean,
                null,
            ),
            $evaluation,
        );
    }

    public function testAnInCodeDefaultValueReturnedForARolloutConfigCarriesNoVariation(): void
    {
        $client = self::readyClient();
        $customer = new Context(id: 'user-123');

        $evaluation = $client->evaluate('temporary-feature-flag', 'fallback', $customer);

        self::assertEquals(
            new ConfigEvaluation(
                'temporary-feature-flag',
                'fallback',
                null,
                null,
                true,
                EvaluationReason::TypeMismatch,
                $customer,
            ),
            $evaluation,
        );
    }

    public function testANullInCodeDefaultValueIsRejected(): void
    {
        $client = self::readyClient();

        $this->expectException(ConfigDirectorValidationException::class);

        $client->getValue('integer-config', null);
    }

    public function testAnObjectInCodeDefaultValueIsRejected(): void
    {
        $client = self::readyClient();

        $this->expectException(ConfigDirectorValidationException::class);

        $client->evaluate('json-value-config', new \stdClass());
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
