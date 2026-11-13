<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Connection;

use ConfigDirector\ConfigState;
use ConfigDirector\Internal\Evaluation\ServedValue;
use ConfigDirector\Internal\Evaluation\TargetingRules;
use ConfigDirector\Internal\Value\EncodedNativeValue;
use ConfigDirector\Internal\Value\UnservableValue;
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

    /**
     * @param array<array-key, mixed> $values
     *
     * @return array<string, self>
     *
     * @throws UnservableValue
     */
    public static function servingEachValueToEveryContext(array $values, string $idPrefix): array
    {
        $definitions = [];
        foreach ($values as $configKey => $value) {
            $configKey = (string) $configKey;
            if (trim($configKey) === '') {
                throw new UnservableValue('Invalid config key. The key of a value cannot be empty.');
            }

            $encodedValue = EncodedNativeValue::of($value, $configKey);
            $definitions[$configKey] = new self(
                "{$idPrefix}{$configKey}",
                $configKey,
                $encodedValue->configType,
                new TargetingRules($encodedValue->servedText, null, null, []),
            );
        }

        return $definitions;
    }

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

    public function isDefinedAs(self $otherDefinition): bool
    {
        return serialize($this) === serialize($otherDefinition);
    }

    /**
     * @param list<string> $segmentIds
     */
    public function usesAnySegmentOf(array $segmentIds): bool
    {
        foreach ($this->targetingRules->rules as $rule) {
            if (array_intersect($rule->segmentIds(), $segmentIds) !== []) {
                return true;
            }
        }

        return false;
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
