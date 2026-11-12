<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Evaluation;

final readonly class TargetingRules
{
    /**
     * @param list<Rule> $rules
     */
    public function __construct(
        public string $defaultValue,
        public ?string $defaultValueId,
        public ?string $defaultVariationId,
        public array $rules,
    ) {}
}
