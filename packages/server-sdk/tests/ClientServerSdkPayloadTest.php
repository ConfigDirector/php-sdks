<?php

declare(strict_types=1);

namespace ConfigDirector\Tests;

use ConfigDirector\ConfigDirector;
use ConfigDirector\ConfigDirectorClient;
use ConfigDirector\ConfigEvaluation;
use ConfigDirector\ConfigState;
use ConfigDirector\ConnectionOptions;
use ConfigDirector\Context;
use ConfigDirector\EvaluationReason;
use ConfigDirector\Tests\Support\RecordingLogger;
use ConfigDirector\Tests\Support\ScriptedSdkServer;
use ConfigDirector\Tests\Support\TemporaryFolder;
use ConfigDirector\Variation;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

final class ClientServerSdkPayloadTest extends TestCase
{
    private string $storagePath;

    /** @var list<ScriptedSdkServer> */
    private array $servers = [];

    #[\Override]
    protected function setUp(): void
    {
        $this->storagePath = TemporaryFolder::create();
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->servers as $server) {
            $server->stop();
        }
        TemporaryFolder::remove($this->storagePath);
    }

    public function testARuleServesItsValueToAMatchingContext(): void
    {
        $client = $this->clientInitializedWith(self::payloadOfAConfigWithARuleForTheProPlan());

        $evaluation = $client->evaluate(
            'checkout-banner',
            'none',
            new Context(id: 'user-1', traits: ['plan' => 'pro']),
        );

        self::assertEquals(
            new ConfigEvaluation(
                'checkout-banner',
                'upgrade-thanks',
                'value-upgrade-thanks',
                null,
                false,
                EvaluationReason::FoundMatch,
                new Context(id: 'user-1', traits: ['plan' => 'pro']),
            ),
            $evaluation,
        );
    }

    public function testTheDefaultTargetingRuleValueIsServedToAContextNoRuleMatches(): void
    {
        $client = $this->clientInitializedWith(self::payloadOfAConfigWithARuleForTheProPlan());

        $evaluation = $client->evaluate(
            'checkout-banner',
            'none',
            new Context(id: 'user-2', traits: ['plan' => 'free']),
        );

        self::assertEquals(
            new ConfigEvaluation(
                'checkout-banner',
                'upgrade-offer',
                'value-id-of-checkout-banner',
                null,
                false,
                EvaluationReason::FoundMatch,
                new Context(id: 'user-2', traits: ['plan' => 'free']),
            ),
            $evaluation,
        );
    }

    public function testARuleWithASegmentConditionServesItsValueToAContextInTheSegment(): void
    {
        $client = $this->clientInitializedWith(self::payloadOfAConfigServedToTheBetaTestersSegment());

        $value = $client->getValue('search-engine', 'none', new Context(id: 'user-1', traits: ['beta' => true]));

        self::assertSame('vector', $value);
    }

    public function testARuleWithASegmentConditionIsSkippedForAContextOutsideTheSegment(): void
    {
        $client = $this->clientInitializedWith(self::payloadOfAConfigServedToTheBetaTestersSegment());

        $value = $client->getValue('search-engine', 'none', new Context(id: 'user-2', traits: ['beta' => false]));

        self::assertSame('keyword', $value);
    }

    public function testAPercentageRolloutServesTheShareThatCoversTheContext(): void
    {
        $client = $this->clientInitializedWith(self::payloadOf([
            'new-checkout' => [
                'id' => '6d5c4b3a-2f1e-4d0c-9b8a-7f6e5d4c3b2a',
                'key' => 'new-checkout',
                'type' => 'string',
                'typeConstraints' => null,
                'variations' => [
                    ['id' => 'variation-control', 'name' => 'Control', 'value' => 'control'],
                    ['id' => 'variation-treatment', 'name' => 'Treatment', 'value' => 'treatment'],
                ],
                'target' => [
                    'environmentId' => '3f1b6c0a-52d4-4e8b-9a7c-0d2e4f6a8b10',
                    'rules' => [
                        [
                            'id' => 'rule-rollout',
                            'order' => 0,
                            'type' => 'percentage',
                            'target' => 'percentage',
                            'percentages' => [
                                [
                                    'id' => 'share-control',
                                    'percentage' => 0,
                                    'value' => 'control',
                                    'valueId' => 'value-control',
                                    'variationId' => 'variation-control',
                                ],
                                [
                                    'id' => 'share-treatment',
                                    'percentage' => 100,
                                    'value' => 'treatment',
                                    'valueId' => 'value-treatment',
                                    'variationId' => 'variation-treatment',
                                ],
                            ],
                            'value' => null,
                            'valueId' => null,
                            'conditions' => null,
                        ],
                    ],
                    'defaultValue' => 'control',
                    'defaultValueId' => 'value-control',
                    'defaultVariationId' => 'variation-control',
                ],
            ],
        ]));

        $state = $client->getAllConfigs(new Context(id: 'user-1'))['new-checkout'];

        self::assertEquals(
            new ConfigState(
                '6d5c4b3a-2f1e-4d0c-9b8a-7f6e5d4c3b2a',
                'new-checkout',
                'string',
                'treatment',
                'value-treatment',
                new Variation('variation-treatment', 'Treatment'),
                'rule-rollout',
                'share-treatment',
            ),
            $state,
        );
    }

    public function testAnUnknownTopLevelFieldIsIgnored(): void
    {
        $payload = self::payloadOf(['page-size' => self::configServing('page-size', 'integer', '25')]);
        $payload['experiments'] = ['checkout-test' => ['status' => 'running']];
        $client = $this->clientInitializedWith($payload);

        $value = $client->getValue('page-size', 10);

        self::assertSame(25, $value);
    }

    public function testAnUnknownFieldOnARuleIsIgnored(): void
    {
        $config = self::configServing('page-size', 'integer', '25');
        $config['target']['rules'] = [
            [
                'id' => 'rule-everyone',
                'order' => 0,
                'type' => 'conditional',
                'target' => 'value',
                'percentages' => null,
                'value' => '50',
                'valueId' => 'value-50',
                'conditions' => [],
                'schedule' => ['startsAt' => '2027-01-01T00:00:00Z'],
            ],
        ];
        $client = $this->clientInitializedWith(self::payloadOf(['page-size' => $config]));

        $value = $client->getValue('page-size', 10);

        self::assertSame(50, $value);
    }

    public function testAConfigOfAnUnknownTypeLeavesTheOtherConfigsServed(): void
    {
        $client = $this->clientInitializedWith(self::payloadOf([
            'cache-lifetime' => self::configServing('cache-lifetime', 'duration', 'PT5M'),
            'page-size' => self::configServing('page-size', 'integer', '25'),
        ]));

        $value = $client->getValue('page-size', 10);

        self::assertSame(25, $value);
    }

    public function testAConfigOfAnUnknownTypeIsServedUnderItsOwnType(): void
    {
        $client = $this->clientInitializedWith(self::payloadOf([
            'cache-lifetime' => self::configServing('cache-lifetime', 'duration', 'PT5M'),
        ]));

        $state = $client->getAllConfigs()['cache-lifetime'];

        self::assertSame('duration', $state->type);
        self::assertSame('PT5M', $state->value);
    }

    public function testAConfigThatCarriesOnlyItsRequiredFieldsIsServed(): void
    {
        $client = $this->clientInitializedWith([
            'configs' => [
                'page-size' => [
                    'id' => '1a2b3c4d-5e6f-4a7b-8c9d-0e1f2a3b4c5d',
                    'key' => 'page-size',
                    'type' => 'integer',
                    'target' => ['defaultValue' => '25'],
                ],
            ],
        ]);

        $value = $client->getValue('page-size', 10);

        self::assertSame(25, $value);
    }

    public function testAConfigWithoutATargetIsSkippedAndTheOtherConfigsAreServed(): void
    {
        $client = $this->clientInitializedWith(self::payloadOf([
            'broken-config' => [
                'id' => '9f8e7d6c-5b4a-4c3d-8e2f-1a0b9c8d7e6f',
                'key' => 'broken-config',
                'type' => 'string',
            ],
            'page-size' => self::configServing('page-size', 'integer', '25'),
        ]));

        $value = $client->getValue('page-size', 10);

        self::assertSame(25, $value);
    }

    public function testAReadOfAConfigThatWasSkippedReturnsTheInCodeDefaultValue(): void
    {
        $client = $this->clientInitializedWith(self::payloadOf([
            'broken-config' => [
                'id' => '9f8e7d6c-5b4a-4c3d-8e2f-1a0b9c8d7e6f',
                'key' => 'broken-config',
                'type' => 'string',
            ],
        ]));

        $evaluation = $client->evaluate('broken-config', 'fallback');

        self::assertEquals(
            new ConfigEvaluation(
                'broken-config',
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

    public function testAConfigWithAConditionOfAnUnknownKindIsSkipped(): void
    {
        $config = self::configServing('page-size', 'integer', '25');
        $config['target']['rules'] = [
            [
                'id' => 'rule-scheduled',
                'order' => 0,
                'type' => 'conditional',
                'target' => 'value',
                'percentages' => null,
                'value' => '50',
                'valueId' => 'value-50',
                'conditions' => [['kind' => 'schedule', 'id' => 'condition-schedule', 'startsAt' => '2027-01-01']],
            ],
        ];
        $client = $this->clientInitializedWith(self::payloadOf(['page-size' => $config]));

        $evaluation = $client->evaluate('page-size', 10);

        self::assertSame(10, $evaluation->value);
        self::assertSame(EvaluationReason::ConfigStateMissing, $evaluation->reason);
    }

    public function testAConfigWithoutAnIdIsSkipped(): void
    {
        $config = self::configServing('page-size', 'integer', '25');
        unset($config['id']);

        $evaluation = $this->evaluationOfThePageSizeDefinedAs($config);

        self::assertSame(EvaluationReason::ConfigStateMissing, $evaluation->reason);
    }

    public function testAConfigWithoutAKeyIsSkipped(): void
    {
        $config = self::configServing('page-size', 'integer', '25');
        unset($config['key']);

        $evaluation = $this->evaluationOfThePageSizeDefinedAs($config);

        self::assertSame(EvaluationReason::ConfigStateMissing, $evaluation->reason);
    }

    public function testAConfigWithoutATypeIsSkipped(): void
    {
        $config = self::configServing('page-size', 'integer', '25');
        unset($config['type']);

        $evaluation = $this->evaluationOfThePageSizeDefinedAs($config);

        self::assertSame(EvaluationReason::ConfigStateMissing, $evaluation->reason);
    }

    public function testAConfigWhoseRulesAreNotAListIsSkipped(): void
    {
        $config = self::configServing('page-size', 'integer', '25');
        $config['target']['rules'] = 'none';

        $evaluation = $this->evaluationOfThePageSizeDefinedAs($config);

        self::assertSame(EvaluationReason::ConfigStateMissing, $evaluation->reason);
    }

    public function testAConfigWithARuleWithoutAnIdIsSkipped(): void
    {
        $rule = self::ruleServingFiftyToTheProPlan();
        unset($rule['id']);

        $evaluation = $this->evaluationOfThePageSizeDefinedAs(self::pageSizeConfigWithTheRule($rule));

        self::assertSame(EvaluationReason::ConfigStateMissing, $evaluation->reason);
    }

    public function testAConfigWithAConditionWithoutAnAttributeIsSkipped(): void
    {
        $rule = self::ruleServingFiftyToTheProPlan();
        unset($rule['conditions'][0]['attribute']);

        $evaluation = $this->evaluationOfThePageSizeDefinedAs(self::pageSizeConfigWithTheRule($rule));

        self::assertSame(EvaluationReason::ConfigStateMissing, $evaluation->reason);
    }

    public function testAConfigWithAConditionWithoutAnOperatorIsSkipped(): void
    {
        $rule = self::ruleServingFiftyToTheProPlan();
        unset($rule['conditions'][0]['operator']);

        $evaluation = $this->evaluationOfThePageSizeDefinedAs(self::pageSizeConfigWithTheRule($rule));

        self::assertSame(EvaluationReason::ConfigStateMissing, $evaluation->reason);
    }

    public function testAConfigWithAConditionWithoutATargetTypeIsSkipped(): void
    {
        $rule = self::ruleServingFiftyToTheProPlan();
        unset($rule['conditions'][0]['targetType']);

        $evaluation = $this->evaluationOfThePageSizeDefinedAs(self::pageSizeConfigWithTheRule($rule));

        self::assertSame(EvaluationReason::ConfigStateMissing, $evaluation->reason);
    }

    public function testAConfigWithAConditionWhoseTargetValueIsAnObjectIsSkipped(): void
    {
        $rule = self::ruleServingFiftyToTheProPlan();
        $rule['conditions'][0]['targetValues'] = [['plan' => 'pro']];

        $evaluation = $this->evaluationOfThePageSizeDefinedAs(self::pageSizeConfigWithTheRule($rule));

        self::assertSame(EvaluationReason::ConfigStateMissing, $evaluation->reason);
    }

    public function testAConfigWithASegmentConditionWithoutASegmentIdIsSkipped(): void
    {
        $rule = self::ruleServingFiftyToTheProPlan();
        $rule['conditions'] = [['kind' => 'segment', 'id' => 'condition-segment', 'operator' => 'in']];

        $evaluation = $this->evaluationOfThePageSizeDefinedAs(self::pageSizeConfigWithTheRule($rule));

        self::assertSame(EvaluationReason::ConfigStateMissing, $evaluation->reason);
    }

    public function testAConfigWithASegmentConditionWithoutAnOperatorIsSkipped(): void
    {
        $rule = self::ruleServingFiftyToTheProPlan();
        $rule['conditions'] = [['kind' => 'segment', 'id' => 'condition-segment', 'segmentId' => 'segment-1']];

        $evaluation = $this->evaluationOfThePageSizeDefinedAs(self::pageSizeConfigWithTheRule($rule));

        self::assertSame(EvaluationReason::ConfigStateMissing, $evaluation->reason);
    }

    public function testAConfigWithARolloutShareWithoutAPercentageIsSkipped(): void
    {
        $rule = self::ruleServingFiftyToTheProPlan();
        $rule['percentages'] = [['id' => 'share-1', 'value' => '50', 'valueId' => 'value-50']];

        $evaluation = $this->evaluationOfThePageSizeDefinedAs(self::pageSizeConfigWithTheRule($rule));

        self::assertSame(EvaluationReason::ConfigStateMissing, $evaluation->reason);
    }

    public function testAConfigWithARolloutShareWithoutAnIdIsSkipped(): void
    {
        $rule = self::ruleServingFiftyToTheProPlan();
        $rule['percentages'] = [['percentage' => 100, 'value' => '50', 'valueId' => 'value-50']];

        $evaluation = $this->evaluationOfThePageSizeDefinedAs(self::pageSizeConfigWithTheRule($rule));

        self::assertSame(EvaluationReason::ConfigStateMissing, $evaluation->reason);
    }

    public function testAConditionWithoutAKindIsReadAsAnAttributeCondition(): void
    {
        $rule = self::ruleServingFiftyToTheProPlan();
        unset($rule['conditions'][0]['kind']);
        $client = $this->clientInitializedWith(
            self::payloadOf(['page-size' => self::pageSizeConfigWithTheRule($rule)]),
        );

        $value = $client->getValue('page-size', 10, new Context(id: 'user-1', traits: ['plan' => 'pro']));

        self::assertSame(50, $value);
    }

    public function testRulesAreEvaluatedInTheOrderTheyCarryNotTheOrderTheyAreListedIn(): void
    {
        $ruleListedFirst = self::ruleServingFiftyToTheProPlan();
        $ruleListedFirst['order'] = 1;
        $ruleListedSecond = self::ruleServingFiftyToTheProPlan();
        $ruleListedSecond['id'] = 'rule-evaluated-first';
        $ruleListedSecond['order'] = 0;
        $ruleListedSecond['value'] = '100';
        $config = self::configServing('page-size', 'integer', '25');
        $config['target']['rules'] = [$ruleListedFirst, $ruleListedSecond];
        $client = $this->clientInitializedWith(self::payloadOf(['page-size' => $config]));

        $value = $client->getValue('page-size', 10, new Context(id: 'user-1', traits: ['plan' => 'pro']));

        self::assertSame(100, $value);
    }

    public function testARuleServesTheVariationItNames(): void
    {
        $rule = self::ruleServingFiftyToTheProPlan();
        $rule['variationId'] = 'variation-large';
        $config = self::pageSizeConfigWithTheRule($rule);
        $config['variations'] = [['id' => 'variation-large', 'name' => 'Large', 'value' => '50']];
        $client = $this->clientInitializedWith(self::payloadOf(['page-size' => $config]));

        $evaluation = $client->evaluate('page-size', 10, new Context(id: 'user-1', traits: ['plan' => 'pro']));

        self::assertEquals(new Variation('variation-large', 'Large'), $evaluation->variation);
    }

    public function testTheDefaultTargetingRuleValueCarriesTheVariationItNames(): void
    {
        $config = self::configServing('page-size', 'integer', '25');
        $config['variations'] = [['id' => 'variation-small', 'name' => 'Small', 'value' => '25']];
        $config['target']['defaultVariationId'] = 'variation-small';
        $client = $this->clientInitializedWith(self::payloadOf(['page-size' => $config]));

        $evaluation = $client->evaluate('page-size', 10);

        self::assertEquals(new Variation('variation-small', 'Small'), $evaluation->variation);
    }

    public function testASegmentWithAGroupThatIsNotAListIsSkipped(): void
    {
        $payload = self::payloadOfAConfigServedToTheBetaTestersSegment();
        $payload['segments'] = ['segment-beta-testers' => ['groups' => ['everyone']]];
        $logger = new RecordingLogger();

        $this->clientInitializedWith($payload, $logger);

        self::assertSame(
            ['segment-beta-testers'],
            array_column($logger->contextsLoggedAt(LogLevel::WARNING), 'segmentId'),
        );
    }

    public function testASegmentWithASegmentConditionInAGroupIsSkipped(): void
    {
        $payload = self::payloadOfAConfigServedToTheBetaTestersSegment();
        $payload['segments'] = [
            'segment-beta-testers' => [
                'groups' => [
                    [['kind' => 'segment', 'id' => 'condition-nested', 'operator' => 'in', 'segmentId' => 'segment-2']],
                ],
            ],
        ];
        $logger = new RecordingLogger();

        $this->clientInitializedWith($payload, $logger);

        self::assertSame(
            ['segment-beta-testers'],
            array_column($logger->contextsLoggedAt(LogLevel::WARNING), 'segmentId'),
        );
    }

    public function testTheRequestThatPolledLogsAWarningNamingTheConfigItSkipped(): void
    {
        $logger = new RecordingLogger();

        $this->clientInitializedWith(
            self::payloadOf([
                'broken-config' => [
                    'id' => '9f8e7d6c-5b4a-4c3d-8e2f-1a0b9c8d7e6f',
                    'key' => 'broken-config',
                    'type' => 'string',
                ],
                'page-size' => self::configServing('page-size', 'integer', '25'),
            ]),
            $logger,
        );

        self::assertSame(['broken-config'], array_column($logger->contextsLoggedAt(LogLevel::WARNING), 'configKey'));
    }

    public function testTheRequestThatPolledLogsTheConfigItSkippedOnce(): void
    {
        $logger = new RecordingLogger();

        $this->clientInitializedWith(
            self::payloadOf([
                'broken-config' => [
                    'id' => '9f8e7d6c-5b4a-4c3d-8e2f-1a0b9c8d7e6f',
                    'key' => 'broken-config',
                    'type' => 'string',
                ],
            ]),
            $logger,
        );

        self::assertSame([], array_column($logger->contextsLoggedAt(LogLevel::DEBUG), 'cause'));
    }

    public function testARequestThatReadsASkippedConfigFromTheLocalStoreLogsItAtDebugLevelOnly(): void
    {
        $this->clientInitializedWith(self::payloadOf([
            'broken-config' => [
                'id' => '9f8e7d6c-5b4a-4c3d-8e2f-1a0b9c8d7e6f',
                'key' => 'broken-config',
                'type' => 'string',
            ],
        ]));
        $loggerOfTheNextRequest = new RecordingLogger();
        $clientOfTheNextRequest = ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            connection: new ConnectionOptions(url: $this->servers[0]->url, storagePath: $this->storagePath),
            logger: $loggerOfTheNextRequest,
        );

        $clientOfTheNextRequest->initialize();

        self::assertSame([], $loggerOfTheNextRequest->contextsLoggedAt(LogLevel::WARNING));
        self::assertSame(
            ['broken-config'],
            array_column($loggerOfTheNextRequest->contextsLoggedAt(LogLevel::DEBUG), 'configKey'),
        );
    }

    public function testASegmentWithoutGroupsIsSkippedSoARuleOnItServesNoOne(): void
    {
        $payload = self::payloadOfAConfigServedToTheBetaTestersSegment();
        $payload['segments'] = ['segment-beta-testers' => ['name' => 'Beta testers']];
        $client = $this->clientInitializedWith($payload);

        $value = $client->getValue('search-engine', 'none', new Context(id: 'user-1', traits: ['beta' => true]));

        self::assertSame('keyword', $value);
    }

    public function testTheRequestThatPolledLogsAWarningNamingTheSegmentItSkipped(): void
    {
        $payload = self::payloadOfAConfigServedToTheBetaTestersSegment();
        $payload['segments'] = ['segment-beta-testers' => ['name' => 'Beta testers']];
        $logger = new RecordingLogger();

        $this->clientInitializedWith($payload, $logger);

        self::assertSame(
            ['segment-beta-testers'],
            array_column($logger->contextsLoggedAt(LogLevel::WARNING), 'segmentId'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function payloadOfAConfigWithARuleForTheProPlan(): array
    {
        $config = self::configServing('checkout-banner', 'string', 'upgrade-offer');
        $config['target']['rules'] = [
            [
                'id' => 'rule-pro-plan',
                'order' => 0,
                'type' => 'conditional',
                'target' => 'value',
                'percentages' => null,
                'value' => 'upgrade-thanks',
                'valueId' => 'value-upgrade-thanks',
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
        ];

        return self::payloadOf(['checkout-banner' => $config]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function payloadOfAConfigServedToTheBetaTestersSegment(): array
    {
        $config = self::configServing('search-engine', 'string', 'keyword');
        $config['target']['rules'] = [
            [
                'id' => 'rule-beta-testers',
                'order' => 0,
                'type' => 'conditional',
                'target' => 'value',
                'percentages' => null,
                'value' => 'vector',
                'valueId' => 'value-vector',
                'conditions' => [
                    [
                        'kind' => 'segment',
                        'id' => 'condition-beta-testers',
                        'operator' => 'in',
                        'segmentId' => 'segment-beta-testers',
                    ],
                ],
            ],
        ];

        return self::payloadOf(
            ['search-engine' => $config],
            [
                'segment-beta-testers' => [
                    'groups' => [
                        [
                            [
                                'kind' => 'attribute',
                                'id' => 'condition-beta-trait',
                                'attribute' => 'traits',
                                'trait' => '/beta',
                                'operator' => 'equals',
                                'targetType' => 'text',
                                'targetValues' => ['true'],
                            ],
                        ],
                    ],
                ],
            ],
        );
    }

    /**
     * @return array{
     *     id: string,
     *     key: string,
     *     type: string,
     *     typeConstraints: null,
     *     variations: list<mixed>,
     *     target: array<string, mixed>,
     * }
     */
    private static function configServing(string $configKey, string $type, string $defaultTargetingRuleValue): array
    {
        return [
            'id' => "id-of-{$configKey}",
            'key' => $configKey,
            'type' => $type,
            'typeConstraints' => null,
            'variations' => [],
            'target' => [
                'environmentId' => '3f1b6c0a-52d4-4e8b-9a7c-0d2e4f6a8b10',
                'rules' => [],
                'defaultValue' => $defaultTargetingRuleValue,
                'defaultValueId' => "value-id-of-{$configKey}",
            ],
        ];
    }

    /**
     * @return array{
     *     id: string,
     *     order: int,
     *     type: string,
     *     target: string,
     *     percentages: mixed,
     *     value: string,
     *     valueId: string,
     *     conditions: list<array<string, mixed>>,
     * }
     */
    private static function ruleServingFiftyToTheProPlan(): array
    {
        return [
            'id' => 'rule-pro-plan',
            'order' => 0,
            'type' => 'conditional',
            'target' => 'value',
            'percentages' => null,
            'value' => '50',
            'valueId' => 'value-50',
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
        ];
    }

    /**
     * @param array<string, mixed> $rule
     *
     * @return array<string, mixed>
     */
    private static function pageSizeConfigWithTheRule(array $rule): array
    {
        $config = self::configServing('page-size', 'integer', '25');
        $config['target']['rules'] = [$rule];

        return $config;
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return ConfigEvaluation<int>
     */
    private function evaluationOfThePageSizeDefinedAs(array $config): ConfigEvaluation
    {
        return $this->clientInitializedWith(self::payloadOf(['page-size' => $config]))->evaluate('page-size', 10);
    }

    /**
     * @param array<string, mixed> $configs
     * @param array<string, mixed> $segments
     *
     * @return array<string, mixed>
     */
    private static function payloadOf(array $configs, array $segments = []): array
    {
        return [
            'payloadVersion' => 1,
            'environmentId' => '3f1b6c0a-52d4-4e8b-9a7c-0d2e4f6a8b10',
            'projectId' => '9c4d2e7f-1a3b-4c5d-8e6f-7a8b9c0d1e20',
            'configs' => $configs,
            'segments' => $segments === [] ? new \stdClass() : $segments,
            'kind' => 'full',
            'timestamp' => '2026-11-12T12:00:00.000000Z',
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function clientInitializedWith(array $payload, ?RecordingLogger $logger = null): ConfigDirectorClient
    {
        $server = ScriptedSdkServer::answering([['status' => 200, 'body' => $payload]]);
        $this->servers[] = $server;
        $client = ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            connection: new ConnectionOptions(url: $server->url, storagePath: $this->storagePath),
            logger: $logger ?? new RecordingLogger(),
        );
        $client->initialize();

        return $client;
    }
}
