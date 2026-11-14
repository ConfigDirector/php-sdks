<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use ConfigDirector\ConfigDirectorClient;
use ConfigDirector\Context;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ConfigsController
{
    private const array CONTEXT_PARAMETERS = ['id', 'kind', 'name', 'anonymous'];

    public function __invoke(Request $request, ConfigDirectorClient $client): JsonResponse
    {
        $context = self::contextFrom($request->query->all());
        $configs = [
            'temporary-feature-flag' => $client->getValue('temporary-feature-flag', true, $context),
            'permanent-kill-switch' => $client->getValue('permanent-kill-switch', false, $context),
            'integer-config' => $client->getValue('integer-config', 10, $context),
            'day-of-the-week-config' => $client->getValue('day-of-the-week-config', 'Friday', $context),
            'json-value-config' => $client->getValue('json-value-config', [], $context),
        ];

        return new JsonResponse($configs, options: JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param array<array-key, mixed> $query
     */
    private static function contextFrom(array $query): Context
    {
        $traits = [];
        foreach ($query as $parameter => $value) {
            if (!in_array($parameter, self::CONTEXT_PARAMETERS, true)) {
                $traits[(string) $parameter] = $value;
            }
        }

        return new Context(
            id: self::textOf($query['id'] ?? null),
            kind: self::textOf($query['kind'] ?? null),
            name: self::textOf($query['name'] ?? null),
            traits: $traits,
            anonymous: ($query['anonymous'] ?? null) === 'true',
        );
    }

    private static function textOf(mixed $parameterValue): ?string
    {
        return is_string($parameterValue) ? $parameterValue : null;
    }
}
