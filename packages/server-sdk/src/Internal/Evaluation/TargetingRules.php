<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Evaluation;

final readonly class TargetingRules
{
    /**
     * @param list<Rule> $rules
     */
    public function __construct(
        public string $defaultTargetingRuleValue,
        public ?string $defaultTargetingRuleValueId,
        public ?string $defaultTargetingRuleVariationId,
        public array $rules,
    ) {}
}
