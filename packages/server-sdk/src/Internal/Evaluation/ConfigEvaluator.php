<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Evaluation;

final class ConfigEvaluator
{
    /**
     * @param array<string, Segment> $segments
     */
    public function evaluate(
        string $configId,
        TargetingRules $targetingRules,
        EvaluationContext $context,
        array $segments,
    ): ServedValue {
        $aRolloutWasSkipped = false;
        foreach (self::inEvaluationOrder($targetingRules->rules) as $rule) {
            $servedValueOrFallThrough = $rule->serve($configId, $context, $segments);
            if ($servedValueOrFallThrough instanceof ServedValue) {
                return $servedValueOrFallThrough;
            }
            $aRolloutWasSkipped = match ($servedValueOrFallThrough) {
                FallThrough::RolloutSkippedNoIdentifier => true,
                FallThrough::NotMatched => $aRolloutWasSkipped,
            };
        }

        return new ServedValue(
            value: $targetingRules->defaultTargetingRuleValue,
            valueId: $targetingRules->defaultTargetingRuleValueId,
            variationId: $targetingRules->defaultTargetingRuleVariationId,
            rolloutShare: null,
            servedBy: $aRolloutWasSkipped
                ? ServedBy::DefaultTargetingRuleAfterSkippedRollout
                : ServedBy::DefaultTargetingRule,
        );
    }

    /**
     * @param list<Rule> $rules
     * @return list<Rule>
     */
    private static function inEvaluationOrder(array $rules): array
    {
        usort($rules, static fn(Rule $rule, Rule $otherRule): int
            => ($rule->order ?? PHP_INT_MAX) <=> ($otherRule->order ?? PHP_INT_MAX));

        return $rules;
    }
}
