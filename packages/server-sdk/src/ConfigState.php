<?php

declare(strict_types=1);

namespace ConfigDirector;

/**
 * What a config serves to one context, before the value is read as a PHP type.
 */
final readonly class ConfigState
{
    /**
     * @param string $id The config's ID.
     * @param string $key The config's key.
     * @param string $type The type the config was created with: `boolean`, `string`, `integer`,
     *     `float`, `enum`, `url`, `json` or `custom`.
     * @param string|null $value The value served, as text.
     * @param string|null $valueId The ID ConfigDirector assigned to the value served, when it sent
     *     one.
     * @param Variation|null $variation The variation that was served, or `null` when the config has
     *     no variations.
     * @param string|null $ruleId The ID of the percentage rollout's targeting rule when a rollout
     *     served the value.
     * @param string|null $shareId The ID of the percentage rollout's share when a rollout served
     *     the value.
     */
    public function __construct(
        public string $id,
        public string $key,
        public string $type,
        public ?string $value,
        public ?string $valueId = null,
        public ?Variation $variation = null,
        public ?string $ruleId = null,
        public ?string $shareId = null,
    ) {}
}
