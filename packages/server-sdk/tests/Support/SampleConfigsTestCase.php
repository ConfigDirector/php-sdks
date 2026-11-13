<?php

declare(strict_types=1);

namespace ConfigDirector\Tests\Support;

use ConfigDirector\ConnectionOptions;
use PHPUnit\Framework\TestCase;

abstract class SampleConfigsTestCase extends TestCase
{
    private static ?ScriptedSdkServer $sdkServer = null;

    private static string $storagePath = '';

    #[\Override]
    public static function setUpBeforeClass(): void
    {
        self::$sdkServer = ScriptedSdkServer::answering([
            ['status' => 200, 'body' => self::serverSdkPayloadOfTheSampleConfigs()],
        ]);
        self::$storagePath = TemporaryFolder::create();
    }

    #[\Override]
    public static function tearDownAfterClass(): void
    {
        self::$sdkServer?->stop();
        self::$sdkServer = null;
        TemporaryFolder::remove(self::$storagePath);
    }

    protected static function connectionToTheSdkServer(): ConnectionOptions
    {
        if (self::$sdkServer === null) {
            throw new \LogicException('The SDK server of the sample configs is not running.');
        }

        return new ConnectionOptions(url: self::$sdkServer->url, storagePath: self::$storagePath);
    }

    /**
     * @return array<string, mixed>
     */
    private static function serverSdkPayloadOfTheSampleConfigs(): array
    {
        $environmentId = '3f1b6c0a-52d4-4e8b-9a7c-0d2e4f6a8b10';

        return [
            'payloadVersion' => 1,
            'environmentId' => $environmentId,
            'projectId' => '9c4d2e7f-1a3b-4c5d-8e6f-7a8b9c0d1e20',
            'configs' => [
                'temporary-feature-flag' => [
                    'id' => '7b0c4f52-3d1e-4a86-9c2f-1e5d8a6b4c01',
                    'key' => 'temporary-feature-flag',
                    'type' => 'boolean',
                    'typeConstraints' => null,
                    'variations' => [
                        ['id' => 'variation-flag-on', 'name' => 'On', 'value' => 'true'],
                        ['id' => 'variation-flag-off', 'name' => 'Off', 'value' => 'false'],
                    ],
                    'target' => [
                        'environmentId' => $environmentId,
                        'rules' => [
                            [
                                'id' => 'rule-flag-rollout',
                                'order' => 0,
                                'type' => 'percentage',
                                'target' => 'percentage',
                                'percentages' => [
                                    [
                                        'id' => 'share-flag-on',
                                        'percentage' => 100.0,
                                        'value' => 'true',
                                        'valueId' => 'value-flag-on',
                                        'variationId' => 'variation-flag-on',
                                    ],
                                ],
                                'value' => null,
                                'valueId' => null,
                                'conditions' => null,
                            ],
                        ],
                        'defaultValue' => 'false',
                        'defaultValueId' => 'value-flag-off',
                        'defaultVariationId' => 'variation-flag-off',
                    ],
                ],
                'permanent-kill-switch' => [
                    'id' => '2f6d1c3e-8a47-4b55-9d0e-6c1f7a9b2e02',
                    'key' => 'permanent-kill-switch',
                    'type' => 'boolean',
                    'typeConstraints' => null,
                    'variations' => [],
                    'target' => [
                        'environmentId' => $environmentId,
                        'rules' => [],
                        'defaultValue' => 'true',
                        'defaultValueId' => 'value-kill-switch-on',
                    ],
                ],
                'integer-config' => [
                    'id' => '9a3e5b71-6c2d-4f08-8b1a-4d7c2e9f5a03',
                    'key' => 'integer-config',
                    'type' => 'integer',
                    'typeConstraints' => null,
                    'variations' => [],
                    'target' => [
                        'environmentId' => $environmentId,
                        'rules' => [
                            [
                                'id' => 'rule-beta-app',
                                'order' => 0,
                                'type' => 'conditional',
                                'target' => 'value',
                                'percentages' => null,
                                'value' => '7',
                                'valueId' => 'value-integer-7',
                                'conditions' => [
                                    [
                                        'kind' => 'attribute',
                                        'id' => 'condition-beta-app',
                                        'attribute' => 'appName',
                                        'trait' => null,
                                        'operator' => 'equals',
                                        'targetType' => 'text',
                                        'targetValues' => ['beta-app'],
                                    ],
                                ],
                            ],
                        ],
                        'defaultValue' => '42',
                        'defaultValueId' => 'value-integer-42',
                    ],
                ],
                'day-of-the-week-config' => [
                    'id' => '5c8a2d94-1b7f-4e63-a0d5-9f3b6c1e7d04',
                    'key' => 'day-of-the-week-config',
                    'type' => 'string',
                    'typeConstraints' => null,
                    'variations' => [],
                    'target' => [
                        'environmentId' => $environmentId,
                        'rules' => [
                            [
                                'id' => 'rule-pro-plan',
                                'order' => 0,
                                'type' => 'conditional',
                                'target' => 'value',
                                'percentages' => null,
                                'value' => 'Tuesday',
                                'valueId' => 'value-tuesday',
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
                        ],
                        'defaultValue' => 'Monday',
                        'defaultValueId' => 'value-monday',
                    ],
                ],
                'json-value-config' => [
                    'id' => 'e1d7f3a6-4c9b-4d21-b8e0-2a6f5c3d9b05',
                    'key' => 'json-value-config',
                    'type' => 'json',
                    'typeConstraints' => null,
                    'variations' => [],
                    'target' => [
                        'environmentId' => $environmentId,
                        'rules' => [],
                        'defaultValue' => '{"greeting":"hello","sizes":[1,2]}',
                        'defaultValueId' => 'value-json-greeting',
                    ],
                ],
                'float-config' => [
                    'id' => '4b9e6c18-7f3a-4c52-9e1d-8c0a7b2f6e06',
                    'key' => 'float-config',
                    'type' => 'float',
                    'typeConstraints' => null,
                    'variations' => [],
                    'target' => [
                        'environmentId' => $environmentId,
                        'rules' => [],
                        'defaultValue' => '26.0',
                        'defaultValueId' => 'value-float-26',
                    ],
                ],
                'config-without-a-value' => [
                    'id' => 'c6a1e8d3-2b5f-4937-a4c8-3e9d1f7b0a07',
                    'key' => 'config-without-a-value',
                    'type' => 'string',
                    'typeConstraints' => null,
                    'variations' => [],
                    'target' => [
                        'environmentId' => $environmentId,
                        'rules' => [],
                        'defaultValue' => '',
                        'defaultValueId' => '',
                    ],
                ],
                'malformed-json-config' => [
                    'id' => '8d2f7a45-9e6c-4b10-8f3e-5b1c4d6a2e08',
                    'key' => 'malformed-json-config',
                    'type' => 'json',
                    'typeConstraints' => null,
                    'variations' => [],
                    'target' => [
                        'environmentId' => $environmentId,
                        'rules' => [],
                        'defaultValue' => '{"greeting":',
                        'defaultValueId' => 'value-malformed-json',
                    ],
                ],
            ],
            'segments' => new \stdClass(),
            'kind' => 'full',
            'timestamp' => '2026-11-12T12:00:00.000000Z',
        ];
    }
}
