<?php

declare(strict_types=1);

namespace App;

use ConfigDirector\ConfigDirectorClient;
use ConfigDirector\Context;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class ConfigsAction
{
    private const array CONTEXT_PARAMETERS = ['id', 'kind', 'name', 'anonymous'];

    public function __construct(private ConfigDirectorClient $client) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $context = self::contextFrom($request->getQueryParams());
        $configs = [
            'temporary-feature-flag' => $this->client->getValue('temporary-feature-flag', true, $context),
            'permanent-kill-switch' => $this->client->getValue('permanent-kill-switch', false, $context),
            'integer-config' => $this->client->getValue('integer-config', 10, $context),
            'day-of-the-week-config' => $this->client->getValue('day-of-the-week-config', 'Friday', $context),
            'json-value-config' => $this->client->getValue('json-value-config', [], $context),
        ];

        return JsonResponse::withBody($response, $configs);
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
