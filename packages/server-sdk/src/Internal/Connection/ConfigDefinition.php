<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Connection;

use ConfigDirector\ConfigState;
use ConfigDirector\Internal\Evaluation\ServedValue;
use ConfigDirector\Internal\Evaluation\TargetingRules;
use ConfigDirector\Variation;

final readonly class ConfigDefinition
{
    /**
     * @param list<Variation> $variations
     */
    public function __construct(
        public string $id,
        public string $key,
        public string $type,
        public TargetingRules $targetingRules,
        private array $variations = [],
    ) {}

    public function stateServing(ServedValue $served): ConfigState
    {
        return new ConfigState(
            $this->id,
            $this->key,
            $this->type,
            $served->value,
            $served->valueId,
            $this->variationWithId($served->variationId),
            $served->rolloutShare?->ruleId,
            $served->rolloutShare?->shareId,
        );
    }

    private function variationWithId(?string $variationId): ?Variation
    {
        foreach ($this->variations as $variation) {
            if ($variation->id === $variationId) {
                return $variation;
            }
        }

        return null;
    }
}
