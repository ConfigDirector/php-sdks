<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Connection;

use ConfigDirector\Internal\Evaluation\AttributeCondition;
use ConfigDirector\Internal\Evaluation\Rule;
use ConfigDirector\Internal\Evaluation\Share;
use ConfigDirector\Internal\Evaluation\TargetingRules;
use ConfigDirector\Variation;

final class HardCodedConnection implements Connection
{
    #[\Override]
    public function payloadDeliveredWithin(int $timeoutMilliseconds): ServerSdkPayload
    {
        $configs = [];
        foreach (self::configDefinitions() as $definition) {
            $configs[$definition->key] = $definition;
        }

        return new ServerSdkPayload($configs, []);
    }

    #[\Override]
    public function close(): void {}

    /**
     * @return list<ConfigDefinition>
     */
    private static function configDefinitions(): array
    {
        return [
            new ConfigDefinition(
                '7b0c4f52-3d1e-4a86-9c2f-1e5d8a6b4c01',
                'temporary-feature-flag',
                'boolean',
                new TargetingRules('false', 'value-flag-off', 'variation-flag-off', [
                    new Rule('rule-flag-rollout', 0, 'percentage', 'percentage', shares: [
                        new Share('share-flag-on', 100.0, 'true', 'value-flag-on', 'variation-flag-on'),
                    ]),
                ]),
                [new Variation('variation-flag-on', 'On'), new Variation('variation-flag-off', 'Off')],
            ),
            new ConfigDefinition(
                '2f6d1c3e-8a47-4b55-9d0e-6c1f7a9b2e02',
                'permanent-kill-switch',
                'boolean',
                new TargetingRules('true', 'value-kill-switch-on', null, []),
            ),
            new ConfigDefinition(
                '9a3e5b71-6c2d-4f08-8b1a-4d7c2e9f5a03',
                'integer-config',
                'integer',
                new TargetingRules('42', 'value-integer-42', null, [
                    new Rule('rule-beta-app', 0, 'conditional', 'value', '7', 'value-integer-7', conditions: [
                        new AttributeCondition('appName', null, 'equals', 'text', ['beta-app']),
                    ]),
                ]),
            ),
            new ConfigDefinition(
                '5c8a2d94-1b7f-4e63-a0d5-9f3b6c1e7d04',
                'day-of-the-week-config',
                'string',
                new TargetingRules('Monday', 'value-monday', null, [
                    new Rule('rule-pro-plan', 0, 'conditional', 'value', 'Tuesday', 'value-tuesday', conditions: [
                        new AttributeCondition('traits', '/plan', 'equals', 'text', ['pro']),
                    ]),
                ]),
            ),
            new ConfigDefinition(
                'e1d7f3a6-4c9b-4d21-b8e0-2a6f5c3d9b05',
                'json-value-config',
                'json',
                new TargetingRules('{"greeting":"hello","sizes":[1,2]}', 'value-json-greeting', null, []),
            ),
            new ConfigDefinition(
                '4b9e6c18-7f3a-4c52-9e1d-8c0a7b2f6e06',
                'float-config',
                'float',
                new TargetingRules('26.0', 'value-float-26', null, []),
            ),
            new ConfigDefinition(
                'c6a1e8d3-2b5f-4937-a4c8-3e9d1f7b0a07',
                'config-without-a-value',
                'string',
                new TargetingRules('', null, null, []),
            ),
            new ConfigDefinition(
                '8d2f7a45-9e6c-4b10-8f3e-5b1c4d6a2e08',
                'malformed-json-config',
                'json',
                new TargetingRules('{"greeting":', 'value-malformed-json', null, []),
            ),
        ];
    }
}
