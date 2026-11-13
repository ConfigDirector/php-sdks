<?php

declare(strict_types=1);

namespace App;

use Psr\Http\Message\ResponseInterface;

final class JsonResponse
{
    private function __construct() {}

    /**
     * @param array<string, mixed> $body
     */
    public static function withBody(ResponseInterface $response, array $body): ResponseInterface
    {
        $response->getBody()->write(json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return $response->withHeader('Content-Type', 'application/json');
    }
}
