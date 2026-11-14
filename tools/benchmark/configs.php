<?php

declare(strict_types=1);

const SEGMENTS_OF_THE_BENCHMARK = 20;

const VALUE_OF_THE_RULES_AND_DEFAULT_TARGETING_RULE_VALUE_BY_CONFIG_TYPE = [
    'string' => ['vector', 'keyword'],
    'integer' => ['25', '50'],
    'json' => ['{"limits":{"daily":100,"burst":20},"regions":["us","eu"]}', '{"limits":{"daily":10}}'],
    'boolean' => ['true', 'false'],
];

const IN_CODE_DEFAULT_VALUE_BY_CONFIG_TYPE = [
    'string' => 'in-code-default',
    'integer' => 10,
    'json' => [],
    'boolean' => false,
];

function typeOfTheConfigNumbered(int $config): string
{
    $configTypes = array_keys(IN_CODE_DEFAULT_VALUE_BY_CONFIG_TYPE);

    return $configTypes[($config - 1) % count($configTypes)];
}
