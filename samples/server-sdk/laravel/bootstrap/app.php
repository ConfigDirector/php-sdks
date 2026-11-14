<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(api: __DIR__ . '/../routes/api.php', apiPrefix: '')
    ->withMiddleware()
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(
            fn(NotFoundHttpException|MethodNotAllowedHttpException $exception): JsonResponse => new JsonResponse(
                ['error' => 'Not found. Try GET /configs'],
                404,
                options: JSON_UNESCAPED_SLASHES,
            ),
        );
        $exceptions->shouldRenderJsonWhen(fn(): bool => true);
    })
    ->create();
