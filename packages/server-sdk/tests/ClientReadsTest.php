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
use Psr\Log\AbstractLogger;

final class ClientReadsTest extends SampleConfigsTestCase
{
    public function testABooleanConfigReturnsTheServedBoolean(): void
    {
        $client = self::readyClient();

        $evaluation = $client->evaluate('permanent-kill-switch', false);

        self::assertEquals(
            new ConfigEvaluation(
                key: 'permanent-kill-switch',
                value: true,
                valueId: 'value-kill-switch-on',
                variation: null,
                isDefaultValue: false,
                reason: EvaluationReason::FoundMatch,
                context: null,
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
                key: 'day-of-the-week-config',
                value: 'Monday',
                valueId: 'value-monday',
                variation: null,
                isDefaultValue: false,
                reason: EvaluationReason::FoundMatch,
                context: null,
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
                key: 'day-of-the-week-config',
                value: 'Tuesday',
                valueId: 'value-tuesday',
                variation: null,
                isDefaultValue: false,
                reason: EvaluationReason::FoundMatch,
                context: $proCustomer,
            ),
            $evaluation,
        );
    }

    public function testAClientServingOneRequestAfterAnotherServesEachTheValueOfItsOwnContext(): void
    {
        $client = self::readyClient();
        $proCustomer = new Context(id: 'user-123', traits: ['plan' => 'pro']);
        $freeCustomer = new Context(id: 'user-456', traits: ['plan' => 'free']);

        $evaluationOfTheFirstRequest = $client->evaluate('day-of-the-week-config', 'Friday', $proCustomer);
        $evaluationOfARequestWithoutAContext = $client->evaluate('day-of-the-week-config', 'Friday');
        $evaluationOfTheThirdRequest = $client->evaluate('day-of-the-week-config', 'Friday', $freeCustomer);
        $evaluationOfTheFirstCustomerAgain = $client->evaluate('day-of-the-week-config', 'Friday', $proCustomer);

        self::assertSame('Tuesday', $evaluationOfTheFirstRequest->value);
        self::assertSame('Monday', $evaluationOfARequestWithoutAContext->value);
        self::assertNull($evaluationOfARequestWithoutAContext->context);
        self::assertSame('Monday', $evaluationOfTheThirdRequest->value);
        self::assertSame($freeCustomer, $evaluationOfTheThirdRequest->context);
        self::assertSame('Tuesday', $evaluationOfTheFirstCustomerAgain->value);
    }

    public function testALoggerThatReadsAConfigForEveryLineReceivesNoLineForItsOwnRead(): void
    {
        $logger = new class extends AbstractLogger {
            public ?ConfigDirectorClient $clientItReads = null;

            public int $linesReceived = 0;

            /**
             * @param array<array-key, mixed> $context
             */
            #[\Override]
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->linesReceived++;
                $this->clientItReads?->getValue('day-of-the-week-config', 'Friday');
            }
        };
        $client = ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            connection: self::connectionToTheSdkServer(),
            logger: $logger,
        );
        $client->initialize();
        $logger->clientItReads = $client;
        $logger->linesReceived = 0;

        $value = $client->getValue('integer-config', 10);

        self::assertSame(42, $value);
        self::assertSame(1, $logger->linesReceived);
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
                key: 'temporary-feature-flag',
                value: true,
                valueId: 'value-flag-on',
                variation: new Variation('variation-flag-on', 'On'),
                isDefaultValue: false,
                reason: EvaluationReason::FoundMatch,
                context: $customer,
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
                key: 'temporary-feature-flag',
                value: false,
                valueId: 'value-flag-off',
                variation: new Variation('variation-flag-off', 'Off'),
                isDefaultValue: false,
                reason: EvaluationReason::RolloutSkippedNoIdentifier,
                context: null,
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
                key: 'no-such-config',
                value: 'fallback',
                valueId: null,
                variation: null,
                isDefaultValue: true,
                reason: EvaluationReason::ConfigStateMissing,
                context: null,
            ),
            $evaluation,
        );
    }

    public function testABlankConfigKeyReturnsTheInCodeDefaultValue(): void
    {
        $client = self::readyClient();

        $evaluation = $client->evaluate('', 'fallback');

        self::assertEquals(
            new ConfigEvaluation(
                key: '',
                value: 'fallback',
                valueId: null,
                variation: null,
                isDefaultValue: true,
                reason: EvaluationReason::ConfigStateMissing,
                context: null,
            ),
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
            new ConfigEvaluation(
                key: 'integer-config',
                value: 10,
                valueId: null,
                variation: null,
                isDefaultValue: true,
                reason: EvaluationReason::ClientNotReady,
                context: null,
            ),
            $evaluation,
        );
    }

    public function testAStringReadOfABooleanConfigReturnsTheInCodeDefaultValue(): void
    {
        $client = self::readyClient();

        $evaluation = $client->evaluate('permanent-kill-switch', 'fallback');

        self::assertEquals(
            new ConfigEvaluation(
                key: 'permanent-kill-switch',
                value: 'fallback',
                valueId: null,
                variation: null,
                isDefaultValue: true,
                reason: EvaluationReason::TypeMismatch,
                context: null,
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
                key: 'config-without-a-value',
                value: 'fallback',
                valueId: null,
                variation: null,
                isDefaultValue: true,
                reason: EvaluationReason::ValueMissing,
                context: null,
            ),
            $evaluation,
        );
        self::assertNull($evaluation->valueId);
    }

    public function testAnIntegerReadOfTextThatIsNotANumberReturnsTheInCodeDefaultValue(): void
    {
        $client = self::readyClient();

        $evaluation = $client->evaluate('day-of-the-week-config', 10);

        self::assertEquals(
            new ConfigEvaluation(
                key: 'day-of-the-week-config',
                value: 10,
                valueId: null,
                variation: null,
                isDefaultValue: true,
                reason: EvaluationReason::InvalidNumber,
                context: null,
            ),
            $evaluation,
        );
    }

    public function testAnArrayReadOfMalformedJsonReturnsTheInCodeDefaultValue(): void
    {
        $client = self::readyClient();

        $evaluation = $client->evaluate('malformed-json-config', ['greeting' => 'fallback']);

        self::assertEquals(
            new ConfigEvaluation(
                key: 'malformed-json-config',
                value: ['greeting' => 'fallback'],
                valueId: null,
                variation: null,
                isDefaultValue: true,
                reason: EvaluationReason::InvalidJson,
                context: null,
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
                key: 'day-of-the-week-config',
                value: true,
                valueId: null,
                variation: null,
                isDefaultValue: true,
                reason: EvaluationReason::InvalidBoolean,
                context: null,
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
                key: 'temporary-feature-flag',
                value: 'fallback',
                valueId: null,
                variation: null,
                isDefaultValue: true,
                reason: EvaluationReason::TypeMismatch,
                context: $customer,
            ),
            $evaluation,
        );
    }

    public function testANullInCodeDefaultValueIsRejected(): void
    {
        $client = self::readyClient();

        $this->expectException(ConfigDirectorValidationException::class);

        // @phpstan-ignore argument.type
        $client->getValue('integer-config', null);
    }

    public function testAnObjectInCodeDefaultValueIsRejected(): void
    {
        $client = self::readyClient();

        $this->expectException(ConfigDirectorValidationException::class);

        // @phpstan-ignore argument.type
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
