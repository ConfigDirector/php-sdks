<?php

declare(strict_types=1);

namespace ConfigDirector\OpenFeature\Tests;

use ConfigDirector\ClientHooks;
use ConfigDirector\ConfigEvaluatedEvent;
use ConfigDirector\ConnectionMode;
use ConfigDirector\ConnectionOptions;
use ConfigDirector\Context;
use ConfigDirector\OpenFeature\ConfigDirectorProvider;
use OpenFeature\implementation\flags\Attributes;
use OpenFeature\implementation\flags\EvaluationContext;
use OpenFeature\isolated\OpenFeatureAPIFactory;
use PHPUnit\Framework\TestCase;

final class ConfigDirectorProviderContextTest extends TestCase
{
    /** @var list<Context|null> */
    private array $contextsEvaluated = [];

    public function testAnEvaluationContextReachesTheEvaluationWithItsTargetingKeyKindNameTraitsAndAnonymousFlag(): void
    {
        $evaluationContext = new EvaluationContext('org-1', new Attributes([
            'kind' => 'organization',
            'name' => 'Acme',
            'traits' => ['plan' => 'pro', 'seats' => 40],
            'anonymous' => true,
        ]));

        $this->evaluateNewCheckoutFor($evaluationContext);

        self::assertEquals(
            [
                new Context(
                    id: 'org-1',
                    kind: 'organization',
                    name: 'Acme',
                    traits: ['plan' => 'pro', 'seats' => 40],
                    anonymous: true,
                ),
            ],
            $this->contextsEvaluated,
        );
    }

    public function testAnIdAttributeIsTheIdOfAContextWithoutATargetingKey(): void
    {
        $evaluationContext = new EvaluationContext(null, new Attributes(['id' => 'user-1']));

        $this->evaluateNewCheckoutFor($evaluationContext);

        self::assertEquals([new Context(id: 'user-1')], $this->contextsEvaluated);
    }

    public function testTheTargetingKeyIsTheIdOfAContextThatAlsoHasAnIdAttribute(): void
    {
        $evaluationContext = new EvaluationContext('user-1', new Attributes(['id' => 'user-2']));

        $this->evaluateNewCheckoutFor($evaluationContext);

        self::assertEquals([new Context(id: 'user-1')], $this->contextsEvaluated);
    }

    public function testAnEmptyEvaluationContextReachesTheEvaluationAsAContextWithNothingSet(): void
    {
        $evaluationContext = new EvaluationContext();

        $this->evaluateNewCheckoutFor($evaluationContext);

        self::assertEquals([new Context()], $this->contextsEvaluated);
    }

    public function testANumberAsTheIdAttributeIsWrittenAsText(): void
    {
        $evaluationContext = new EvaluationContext(null, new Attributes(['id' => 42]));

        $this->evaluateNewCheckoutFor($evaluationContext);

        self::assertEquals([new Context(id: '42')], $this->contextsEvaluated);
    }

    public function testABooleanAsTheNameIsWrittenAsTrueOrFalse(): void
    {
        $evaluationContext = new EvaluationContext('user-1', new Attributes(['name' => false]));

        $this->evaluateNewCheckoutFor($evaluationContext);

        self::assertEquals([new Context(id: 'user-1', name: 'false')], $this->contextsEvaluated);
    }

    public function testADateAsTheIdAttributeIsWrittenInIso8601(): void
    {
        $evaluationContext = new EvaluationContext(null, new Attributes([
            'id' => new \DateTime('2026-11-15T10:30:00', new \DateTimeZone('UTC')),
        ]));

        $this->evaluateNewCheckoutFor($evaluationContext);

        self::assertEquals([new Context(id: '2026-11-15T10:30:00+00:00')], $this->contextsEvaluated);
    }

    public function testAListAsTheIdAttributeIsLeftOut(): void
    {
        $evaluationContext = new EvaluationContext(null, new Attributes(['id' => ['user-1', 'user-2']]));

        $this->evaluateNewCheckoutFor($evaluationContext);

        self::assertEquals([new Context()], $this->contextsEvaluated);
    }

    public function testAKindInsideTheTraitsStaysATrait(): void
    {
        $evaluationContext = new EvaluationContext('user-1', new Attributes(['traits' => ['kind' => 'organization']]));

        $this->evaluateNewCheckoutFor($evaluationContext);

        self::assertEquals(
            [new Context(id: 'user-1', traits: ['kind' => 'organization'])],
            $this->contextsEvaluated,
        );
    }

    public function testAContextWhoseKindIsNotTextIsIgnored(): void
    {
        $evaluationContext = new EvaluationContext('device-1', new Attributes(['kind' => 7]));

        $this->evaluateNewCheckoutFor($evaluationContext);

        self::assertSame([null], $this->contextsEvaluated);
    }

    public function testTraitsThatAreNotAnArrayAreLeftOut(): void
    {
        $evaluationContext = new EvaluationContext('user-1', new Attributes(['traits' => 'pro']));

        $this->evaluateNewCheckoutFor($evaluationContext);

        self::assertEquals([new Context(id: 'user-1')], $this->contextsEvaluated);
    }

    public function testDatesInsideTheTraitsAreWrittenInIso8601(): void
    {
        $evaluationContext = new EvaluationContext('user-1', new Attributes([
            'traits' => [
                'signedUpAt' => new \DateTimeImmutable('2026-11-15T10:30:00', new \DateTimeZone('UTC')),
                'billing' => [
                    'renewsAt' => new \DateTimeImmutable('2026-12-15T08:00:00.250000', new \DateTimeZone('+02:00')),
                ],
            ],
        ]));

        $this->evaluateNewCheckoutFor($evaluationContext);

        self::assertEquals(
            [
                new Context(id: 'user-1', traits: [
                    'signedUpAt' => '2026-11-15T10:30:00+00:00',
                    'billing' => ['renewsAt' => '2026-12-15T08:00:00.250000+02:00'],
                ]),
            ],
            $this->contextsEvaluated,
        );
    }

    public function testAnAnonymousAttributeThatIsNotABooleanLeavesTheContextNotAnonymous(): void
    {
        $evaluationContext = new EvaluationContext('user-1', new Attributes(['anonymous' => 'yes']));

        $this->evaluateNewCheckoutFor($evaluationContext);

        self::assertEquals([new Context(id: 'user-1')], $this->contextsEvaluated);
    }

    public function testAReadWithoutAnEvaluationContextIsEvaluatedWithoutAContext(): void
    {
        $provider = $this->offlineProviderRecordingTheContextsEvaluated();

        $provider->resolveBooleanValue('new-checkout', false);

        self::assertSame([null], $this->contextsEvaluated);
    }

    private function evaluateNewCheckoutFor(EvaluationContext $evaluationContext): void
    {
        $api = OpenFeatureAPIFactory::createAPI();
        $api->setProvider($this->offlineProviderRecordingTheContextsEvaluated());

        $api->getClient(null, null)->getBooleanValue('new-checkout', false, $evaluationContext);
    }

    private function offlineProviderRecordingTheContextsEvaluated(): ConfigDirectorProvider
    {
        return new ConfigDirectorProvider(
            connection: new ConnectionOptions(mode: ConnectionMode::Offline, values: ['new-checkout' => true]),
            hooks: new ClientHooks(configEvaluated: function (ConfigEvaluatedEvent $event): void {
                $this->contextsEvaluated[] = $event->evaluation->context;
            }),
        );
    }
}
