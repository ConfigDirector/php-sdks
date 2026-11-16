<?php

declare(strict_types=1);

namespace App;

use OpenFeature\implementation\flags\Attributes;
use OpenFeature\implementation\flags\EvaluationContext;
use OpenFeature\interfaces\flags\Client;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class ConfigsAction
{
    private const array CONTEXT_PARAMETERS = ['id', 'kind', 'name', 'anonymous'];

    public function __construct(private Client $client) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $context = self::evaluationContextFrom($request->getQueryParams());
        $configs = [
            'temporary-feature-flag' => $this->client->getBooleanValue('temporary-feature-flag', true, $context),
            'permanent-kill-switch' => $this->client->getBooleanValue('permanent-kill-switch', false, $context),
            'integer-config' => $this->client->getIntegerValue('integer-config', 10, $context),
            'day-of-the-week-config' => $this->client->getStringValue('day-of-the-week-config', 'Friday', $context),
            'json-value-config' => $this->client->getObjectValue('json-value-config', [], $context),
        ];

        return JsonResponse::withBody($response, $configs);
    }

    /**
     * @param array<array-key, mixed> $query
     */
    private static function evaluationContextFrom(array $query): EvaluationContext
    {
        $traits = [];
        foreach ($query as $parameter => $value) {
            if (!in_array($parameter, self::CONTEXT_PARAMETERS, true)) {
                $traits[(string) $parameter] = $value;
            }
        }

        return new EvaluationContext(
            self::textOf($query['id'] ?? null),
            new Attributes([
                'kind' => self::textOf($query['kind'] ?? null),
                'name' => self::textOf($query['name'] ?? null),
                'traits' => $traits,
                'anonymous' => ($query['anonymous'] ?? null) === 'true',
            ]),
        );
    }

    private static function textOf(mixed $parameterValue): ?string
    {
        return is_string($parameterValue) ? $parameterValue : null;
    }
}
