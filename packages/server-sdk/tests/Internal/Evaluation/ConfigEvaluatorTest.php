<?php

declare(strict_types=1);

namespace ConfigDirector\Tests\Internal\Evaluation;

use ConfigDirector\Internal\Evaluation\AttributeCondition;
use ConfigDirector\Internal\Evaluation\Condition;
use ConfigDirector\Internal\Evaluation\ConfigEvaluator;
use ConfigDirector\Internal\Evaluation\EvaluationContext;
use ConfigDirector\Internal\Evaluation\RolloutShare;
use ConfigDirector\Internal\Evaluation\Rule;
use ConfigDirector\Internal\Evaluation\Segment;
use ConfigDirector\Internal\Evaluation\SegmentCondition;
use ConfigDirector\Internal\Evaluation\ServedBy;
use ConfigDirector\Internal\Evaluation\ServedValue;
use ConfigDirector\Internal\Evaluation\Share;
use ConfigDirector\Internal\Evaluation\TargetingRules;
use PHPUnit\Framework\TestCase;

final class ConfigEvaluatorTest extends TestCase
{
    private const string CONTRACT_CONFIG_ID = '11111111-1111-4111-8111-111111111111';

    private const string ACME_SEGMENT_ID = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    public function testAConfigWithoutRulesServesTheDefaultTargetingRuleValue(): void
    {
        $servedValue = self::evaluate([], new EvaluationContext(identifier: 'u1'));

        self::assertEquals(new ServedValue(
            value: 'NONE',
            valueId: 'default-value',
            variationId: null,
            rolloutShare: null,
            servedBy: ServedBy::DefaultTargetingRule,
        ), $servedValue);
    }

    public function testTheFirstRuleThatAppliesServesItsValue(): void
    {
        $rules = [
            self::valueRule('first', 0, 'A', self::identifierIs('u2')),
            self::valueRule('second', 1, 'B', self::identifierIs('u1')),
            self::valueRule('third', 2, 'C', self::identifierIs('u1')),
        ];

        $servedValue = self::evaluate($rules, new EvaluationContext(identifier: 'u1'));

        self::assertEquals(new ServedValue(
            value: 'B',
            valueId: 'second-value',
            variationId: null,
            rolloutShare: null,
            servedBy: ServedBy::MatchingRule,
        ), $servedValue);
    }

    public function testRulesAreTriedInAscendingOrderRegardlessOfTheirPosition(): void
    {
        $rules = [self::valueRule('listed-first', 1, 'B'), self::valueRule('listed-second', 0, 'A')];

        $servedValue = self::evaluate($rules, new EvaluationContext(identifier: 'u1'));

        self::assertSame('A', $servedValue->value);
    }

    public function testRulesWithoutAnOrderAreTriedAfterEveryOrderedRule(): void
    {
        $rules = [self::valueRule('unordered', null, 'C'), self::valueRule('ordered', 5, 'A')];

        $servedValue = self::evaluate($rules, new EvaluationContext(identifier: 'u1'));

        self::assertSame('A', $servedValue->value);
    }

    public function testRulesWithoutAnOrderKeepTheOrderListed(): void
    {
        $rules = [self::valueRule('unordered-first', null, 'C'), self::valueRule('unordered-second', null, 'D')];

        $servedValue = self::evaluate($rules, new EvaluationContext(identifier: 'u1'));

        self::assertSame('C', $servedValue->value);
    }

    public function testNoRuleApplyingServesTheDefaultTargetingRuleValue(): void
    {
        $rules = [self::valueRule('only', 0, 'A', self::identifierIs('u2'))];

        $servedValue = self::evaluate($rules, new EvaluationContext(identifier: 'u1'));

        self::assertEquals(new ServedValue(
            value: 'NONE',
            valueId: 'default-value',
            variationId: null,
            rolloutShare: null,
            servedBy: ServedBy::DefaultTargetingRule,
        ), $servedValue);
    }

    public function testAConditionalRuleAppliesWhenEveryConditionHolds(): void
    {
        $rules = [self::valueRule('both', 0, 'A', self::identifierIs('u1'), self::planIs('pro'))];

        $servedValue = self::evaluate($rules, new EvaluationContext(identifier: 'u1', traits: ['plan' => 'pro']));

        self::assertSame(ServedBy::MatchingRule, $servedValue->servedBy);
    }

    public function testAConditionalRuleDoesNotApplyWhenOneConditionFails(): void
    {
        $rules = [self::valueRule('both', 0, 'A', self::identifierIs('u1'), self::planIs('pro'))];

        $servedValue = self::evaluate($rules, new EvaluationContext(identifier: 'u1', traits: ['plan' => 'free']));

        self::assertSame(ServedBy::DefaultTargetingRule, $servedValue->servedBy);
    }

    public function testARuleWithNoConditionsAppliesToEveryContext(): void
    {
        $servedValue = self::evaluate([self::valueRule('everyone', 0, 'A')], new EvaluationContext());

        self::assertSame('A', $servedValue->value);
    }

    public function testARuleWithAnInSegmentConditionAppliesToAContextInTheSegment(): void
    {
        $rules = [self::valueRule('acme', 0, 'A', new SegmentCondition('in', self::ACME_SEGMENT_ID))];

        $servedValue = self::evaluate(
            $rules,
            new EvaluationContext(traits: ['email' => 'ann@acme.com']),
            self::acmeEmployees(),
        );

        self::assertSame('A', $servedValue->value);
    }

    public function testARuleWithANotInSegmentConditionAppliesToAContextOutsideTheSegment(): void
    {
        $rules = [self::valueRule('not-acme', 0, 'A', new SegmentCondition('not in', self::ACME_SEGMENT_ID))];

        $servedValue = self::evaluate(
            $rules,
            new EvaluationContext(traits: ['email' => 'bob@example.com']),
            self::acmeEmployees(),
        );

        self::assertSame('A', $servedValue->value);
    }

    public function testARuleWithASegmentConditionCombinesWithAnAttributeConditionByAnd(): void
    {
        $rules = [self::valueRule('acme-pro', 0, 'A', new SegmentCondition('in', self::ACME_SEGMENT_ID), self::planIs('pro'))];

        $servedValue = self::evaluate(
            $rules,
            new EvaluationContext(traits: ['email' => 'ann@acme.com', 'plan' => 'free']),
            self::acmeEmployees(),
        );

        self::assertSame('NONE', $servedValue->value);
    }

    public function testARuleWithASegmentConditionFallsThroughWhenThePayloadHasNoSegments(): void
    {
        $rules = [
            self::valueRule('not-acme', 0, 'A', new SegmentCondition('not in', self::ACME_SEGMENT_ID)),
            self::valueRule('next', 1, 'B'),
        ];

        $servedValue = self::evaluate($rules, new EvaluationContext(traits: ['email' => 'bob@example.com']));

        self::assertSame('B', $servedValue->value);
    }

    public function testARuleOfAnUnknownTypeIsSkipped(): void
    {
        $rules = [
            new Rule('unknown', 0, 'schedule', 'value', 'A', 'unknown-value'),
            self::valueRule('known', 1, 'B'),
        ];

        $servedValue = self::evaluate($rules, new EvaluationContext());

        self::assertSame('B', $servedValue->value);
    }

    public function testAConditionalRuleWithAnUnknownTargetIsSkipped(): void
    {
        $rules = [
            new Rule('unknown', 0, 'conditional', 'variation', 'A', 'unknown-value'),
            self::valueRule('known', 1, 'B'),
        ];

        $servedValue = self::evaluate($rules, new EvaluationContext());

        self::assertSame('B', $servedValue->value);
    }

    public function testARuleServingAValueWithoutAValueIsSkipped(): void
    {
        $rules = [
            new Rule('valueless', 0, 'conditional', 'value', null),
            self::valueRule('next', 1, 'B'),
        ];

        $servedValue = self::evaluate($rules, new EvaluationContext());

        self::assertSame('B', $servedValue->value);
    }

    public function testARuleServingASingleValueReportsItsVariationAndNoRolloutShare(): void
    {
        $rules = [new Rule('only', 0, 'conditional', 'value', 'A', 'only-value', 'treatment')];

        $servedValue = self::evaluate($rules, new EvaluationContext());

        self::assertEquals(new ServedValue(
            value: 'A',
            valueId: 'only-value',
            variationId: 'treatment',
            rolloutShare: null,
            servedBy: ServedBy::MatchingRule,
        ), $servedValue);
    }

    public function testTheDefaultTargetingRuleValueReportsItsVariation(): void
    {
        $servedValue = self::evaluate(
            [],
            new EvaluationContext(identifier: 'u1'),
            defaultTargetingRuleVariationId: 'control',
        );

        self::assertEquals(new ServedValue(
            value: 'NONE',
            valueId: 'default-value',
            variationId: 'control',
            rolloutShare: null,
            servedBy: ServedBy::DefaultTargetingRule,
        ), $servedValue);
    }

    public function testAConditionalRuleCanServeAPercentageRollout(): void
    {
        $rules = [
            new Rule(
                'rollout',
                0,
                'conditional',
                'percentage',
                conditions: [self::identifierIs('u582')],
                shares: [self::share('b0', 50.0, '0'), self::share('b1', 50.0, '1')],
            ),
        ];

        $servedValue = self::evaluate($rules, new EvaluationContext(identifier: 'u582'));

        self::assertEquals(
            new ServedValue(
                value: '1',
                valueId: 'b1-value',
                variationId: null,
                rolloutShare: new RolloutShare(ruleId: 'rollout', shareId: 'b1'),
                servedBy: ServedBy::MatchingRule,
            ),
            $servedValue,
        );
    }

    public function testAContextOnAShareBoundaryTakesTheShareThatStartsThere(): void
    {
        $rules = [self::percentageRule(self::share('b0', 50.0, '0'), self::share('b1', 50.0, '1'))];

        $servedValue = self::evaluate($rules, new EvaluationContext(identifier: 'u582'));

        self::assertEquals(
            new ServedValue(
                value: '1',
                valueId: 'b1-value',
                variationId: null,
                rolloutShare: new RolloutShare(ruleId: 'rollout', shareId: 'b1'),
                servedBy: ServedBy::MatchingRule,
            ),
            $servedValue,
        );
    }

    public function testAContextJustBelowAShareBoundaryTakesTheShareThatEndsThere(): void
    {
        $rules = [self::percentageRule(self::share('b0', 50.0, '0'), self::share('b1', 50.0, '1'))];

        $servedValue = self::evaluate($rules, new EvaluationContext(identifier: 'u4331'));

        self::assertSame('0', $servedValue->value);
    }

    public function testAZeroWidthShareOnABoundaryServesNobody(): void
    {
        $rules = [
            self::percentageRule(self::share('b0', 50.0, '0'), self::share('b1', 0.0, '1'), self::share('b2', 50.0, '2')),
        ];

        $servedValue = self::evaluate($rules, new EvaluationContext(identifier: 'u582'));

        self::assertSame('2', $servedValue->value);
    }

    public function testALeadingZeroWidthShareServesNobody(): void
    {
        $rules = [self::percentageRule(self::share('b0', 0.0, '0'), self::share('b1', 100.0, '1'))];

        $servedValue = self::evaluate($rules, new EvaluationContext(identifier: 'user-1'));

        self::assertSame('1', $servedValue->value);
    }

    public function testARolloutReportsItsRuleShareAndVariation(): void
    {
        $rules = [
            self::percentageRule(
                self::share('b0', 50.0, '0'),
                new Share('b1', 50.0, '1', 'b1-value', 'treatment'),
            ),
        ];

        $servedValue = self::evaluate($rules, new EvaluationContext(identifier: 'u582'));

        self::assertEquals(
            new ServedValue(
                value: '1',
                valueId: 'b1-value',
                variationId: 'treatment',
                rolloutShare: new RolloutShare(ruleId: 'rollout', shareId: 'b1'),
                servedBy: ServedBy::MatchingRule,
            ),
            $servedValue,
        );
    }

    public function testAUserLandsInTheShareItsIdentifierHashesTo(): void
    {
        $rules = [self::percentageRule(self::share('b0', 50.0, '0'), self::share('b1', 50.0, '1'))];

        $servedValue = self::evaluate($rules, new EvaluationContext(identifier: 'user-1'));

        self::assertSame('0', $servedValue->value);
    }

    public function testADeviceWithTheSameIdentifierAsAUserCanLandInAnotherShare(): void
    {
        $rules = [self::percentageRule(self::share('b0', 50.0, '0'), self::share('b1', 50.0, '1'))];

        $servedValue = self::evaluate($rules, new EvaluationContext(identifier: 'user-1', contextKind: 'device'));

        self::assertSame('1', $servedValue->value);
    }

    public function testAnExplicitUserKindLandsWhereAnOmittedKindLands(): void
    {
        $rules = [self::percentageRule(self::share('b0', 50.0, '0'), self::share('b1', 50.0, '1'))];

        $servedValue = self::evaluate($rules, new EvaluationContext(identifier: 'user-1', contextKind: 'user'));

        self::assertSame('0', $servedValue->value);
    }

    public function testAnEmptyIdentifierIsStillBucketed(): void
    {
        $rules = [self::percentageRule(self::share('b0', 50.0, '0'), self::share('b1', 50.0, '1'))];

        $servedValue = self::evaluate($rules, new EvaluationContext(identifier: ''));

        self::assertSame('1', $servedValue->value);
    }

    public function testSharesThatDoNotCoverTheContextServeTheDefaultTargetingRuleValue(): void
    {
        $rules = [self::percentageRule(self::share('b0', 10.0, '0'), self::share('b1', 10.0, '1'))];

        $servedValue = self::evaluate($rules, new EvaluationContext(identifier: 'u582'));

        self::assertEquals(new ServedValue(
            value: 'NONE',
            valueId: 'default-value',
            variationId: null,
            rolloutShare: null,
            servedBy: ServedBy::DefaultTargetingRule,
        ), $servedValue);
    }

    public function testARolloutCoveringNobodyFallsThroughToTheNextRule(): void
    {
        $rules = [self::percentageRule(self::share('b0', 0.0, '0')), self::valueRule('next', 1, 'B')];

        $servedValue = self::evaluate($rules, new EvaluationContext(identifier: 'u582'));

        self::assertSame('B', $servedValue->value);
    }

    public function testAShareWithoutAValueFallsThroughToTheNextRule(): void
    {
        $rules = [self::percentageRule(new Share('b0', 100.0, null)), self::valueRule('next', 1, 'B')];

        $servedValue = self::evaluate($rules, new EvaluationContext(identifier: 'u582'));

        self::assertSame('B', $servedValue->value);
    }

    public function testAContextWithoutAnIdentifierSkipsTheRollout(): void
    {
        $rules = [self::percentageRule(self::share('b0', 0.0, '0'), self::share('b1', 10.0, '1'), self::share('b2', 90.0, '2'))];

        $servedValue = self::evaluate($rules, new EvaluationContext());

        self::assertEquals(
            new ServedValue(
                value: 'NONE',
                valueId: 'default-value',
                variationId: null,
                rolloutShare: null,
                servedBy: ServedBy::DefaultTargetingRuleAfterSkippedRollout,
            ),
            $servedValue,
        );
    }

    public function testADeviceWithoutAnIdentifierSkipsTheRolloutToo(): void
    {
        $rules = [self::percentageRule(self::share('b0', 0.0, '0'), self::share('b1', 10.0, '1'), self::share('b2', 90.0, '2'))];

        $servedValue = self::evaluate($rules, new EvaluationContext(contextKind: 'device'));

        self::assertEquals(
            new ServedValue(
                value: 'NONE',
                valueId: 'default-value',
                variationId: null,
                rolloutShare: null,
                servedBy: ServedBy::DefaultTargetingRuleAfterSkippedRollout,
            ),
            $servedValue,
        );
    }

    public function testASkippedRolloutReportsTheVariationOfTheDefaultTargetingRuleValue(): void
    {
        $rules = [self::percentageRule(self::share('b0', 100.0, '0'))];

        $servedValue = self::evaluate($rules, new EvaluationContext(), defaultTargetingRuleVariationId: 'control');

        self::assertEquals(
            new ServedValue(
                value: 'NONE',
                valueId: 'default-value',
                variationId: 'control',
                rolloutShare: null,
                servedBy: ServedBy::DefaultTargetingRuleAfterSkippedRollout,
            ),
            $servedValue,
        );
    }

    public function testAContextWithoutAnIdentifierIsServedByTheRuleAfterASkippedRollout(): void
    {
        $rules = [self::percentageRule(self::share('b0', 100.0, '0')), self::valueRule('next', 1, 'B')];

        $servedValue = self::evaluate($rules, new EvaluationContext());

        self::assertEquals(new ServedValue(
            value: 'B',
            valueId: 'next-value',
            variationId: null,
            rolloutShare: null,
            servedBy: ServedBy::MatchingRule,
        ), $servedValue);
    }

    public function testAContextWithoutAnIdentifierMatchingAConditionalRolloutIsServedByALaterRuleOnTheAppVersion(): void
    {
        $rules = [
            new Rule(
                'rollout',
                0,
                'conditional',
                'percentage',
                conditions: [self::planIs('pro')],
                shares: [self::share('b0', 100.0, '0')],
            ),
            self::valueRule('version-2', 1, 'B', new AttributeCondition('appVersion', null, '>=', 'semver', ['2.0.0'])),
        ];

        $servedValue = self::evaluate($rules, new EvaluationContext(traits: ['plan' => 'pro'], appVersion: '2.1.0'));

        self::assertEquals(new ServedValue(
            value: 'B',
            valueId: 'version-2-value',
            variationId: null,
            rolloutShare: null,
            servedBy: ServedBy::MatchingRule,
        ), $servedValue);
    }

    public function testAContextWithoutAnIdentifierMatchingNoRuleAfterASkippedRolloutGetsTheDefaultTargetingRuleValue(): void
    {
        $rules = [self::percentageRule(self::share('b0', 100.0, '0')), self::valueRule('next', 1, 'B', self::planIs('pro'))];

        $servedValue = self::evaluate($rules, new EvaluationContext(traits: ['plan' => 'free']));

        self::assertSame(ServedBy::DefaultTargetingRuleAfterSkippedRollout, $servedValue->servedBy);
    }

    public function testARuleBeforeTheRolloutStillServesAContextWithoutAnIdentifier(): void
    {
        $rules = [
            new Rule('rollout', 1, 'percentage', 'percentage', shares: [self::share('b0', 100.0, '0')]),
            self::valueRule('first', 0, 'A'),
        ];

        $servedValue = self::evaluate($rules, new EvaluationContext());

        self::assertEquals(new ServedValue(
            value: 'A',
            valueId: 'first-value',
            variationId: null,
            rolloutShare: null,
            servedBy: ServedBy::MatchingRule,
        ), $servedValue);
    }

    public function testAConditionalRolloutWhoseConditionsHoldIsSkippedForAContextWithoutAnIdentifier(): void
    {
        $rules = [
            new Rule(
                'rollout',
                0,
                'conditional',
                'percentage',
                conditions: [self::planIs('pro')],
                shares: [self::share('b0', 100.0, '0')],
            ),
        ];

        $servedValue = self::evaluate($rules, new EvaluationContext(traits: ['plan' => 'pro']));

        self::assertSame(ServedBy::DefaultTargetingRuleAfterSkippedRollout, $servedValue->servedBy);
    }

    public function testAConditionalRolloutWhoseConditionsFailIsNotSkippedForAContextWithoutAnIdentifier(): void
    {
        $rules = [
            new Rule(
                'rollout',
                0,
                'conditional',
                'percentage',
                conditions: [self::planIs('pro')],
                shares: [self::share('b0', 100.0, '0')],
            ),
        ];

        $servedValue = self::evaluate($rules, new EvaluationContext(traits: ['plan' => 'free']));

        self::assertSame(ServedBy::DefaultTargetingRule, $servedValue->servedBy);
    }

    public function testARolloutWithoutAShareListIsNotSkippedForAContextWithoutAnIdentifier(): void
    {
        $rules = [new Rule('rollout', 0, 'percentage', 'percentage')];

        $servedValue = self::evaluate($rules, new EvaluationContext());

        self::assertSame(ServedBy::DefaultTargetingRule, $servedValue->servedBy);
    }

    public function testARolloutWithAnEmptyShareListIsSkippedForAContextWithoutAnIdentifier(): void
    {
        $rules = [self::percentageRule()];

        $servedValue = self::evaluate($rules, new EvaluationContext());

        self::assertSame(ServedBy::DefaultTargetingRuleAfterSkippedRollout, $servedValue->servedBy);
    }

    public function testARolloutWithAnEmptyShareListFallsThroughForAContextWithAnIdentifier(): void
    {
        $rules = [self::percentageRule(), self::valueRule('next', 1, 'B')];

        $servedValue = self::evaluate($rules, new EvaluationContext(identifier: 'u1'));

        self::assertSame('B', $servedValue->value);
    }

    /**
     * @param list<Rule> $rules
     * @param array<string, Segment> $segments
     */
    private static function evaluate(
        array $rules,
        EvaluationContext $context,
        array $segments = [],
        ?string $defaultTargetingRuleVariationId = null,
    ): ServedValue {
        $targetingRules = new TargetingRules(
            defaultTargetingRuleValue: 'NONE',
            defaultTargetingRuleValueId: 'default-value',
            defaultTargetingRuleVariationId: $defaultTargetingRuleVariationId,
            rules: $rules,
        );

        return (new ConfigEvaluator())->evaluate(self::CONTRACT_CONFIG_ID, $targetingRules, $context, $segments);
    }

    private static function valueRule(string $id, ?int $order, string $value, Condition ...$conditions): Rule
    {
        return new Rule($id, $order, 'conditional', 'value', $value, $id . '-value', conditions: array_values($conditions));
    }

    private static function percentageRule(Share ...$shares): Rule
    {
        return new Rule('rollout', 0, 'percentage', 'percentage', shares: array_values($shares));
    }

    private static function share(string $id, float $percentage, string $value): Share
    {
        return new Share($id, $percentage, $value, $id . '-value');
    }

    private static function identifierIs(string $identifier): AttributeCondition
    {
        return new AttributeCondition('identifier', null, 'equals', 'text', [$identifier]);
    }

    private static function planIs(string $plan): AttributeCondition
    {
        return new AttributeCondition('traits', '/plan', 'equals', 'text', [$plan]);
    }

    /**
     * @return array<string, Segment>
     */
    private static function acmeEmployees(): array
    {
        $emailAtAcme = new AttributeCondition('traits', '/email', 'ends with any of', 'text', ['@acme.com']);

        return [self::ACME_SEGMENT_ID => new Segment([[$emailAtAcme]])];
    }
}
