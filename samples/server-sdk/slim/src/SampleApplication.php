<?php

declare(strict_types=1);

namespace App;

use ConfigDirector\ConfigDirector;
use ConfigDirector\ConfigDirectorClient;
use ConfigDirector\Metadata;
use DI\Container;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Exception\HttpNotFoundException;
use Slim\Factory\AppFactory;

final class SampleApplication
{
    private function __construct() {}

    /**
     * @return App<Container>
     */
    public static function create(ConfigDirectorSettings $settings): App
    {
        $container = new Container([
            ConfigDirectorClient::class => fn(): ConfigDirectorClient => self::initializedClient($settings),
        ]);
        $app = AppFactory::createFromContainer($container);
        $app->get('/configs', ConfigsAction::class);
        $app->addRoutingMiddleware();
        $app->addErrorMiddleware(displayErrorDetails: false, logErrors: true, logErrorDetails: true)
            ->setErrorHandler(
                [HttpNotFoundException::class, HttpMethodNotAllowedException::class],
                fn(): ResponseInterface => self::notFoundResponse($app->getResponseFactory()),
            );

        return $app;
    }

    private static function initializedClient(ConfigDirectorSettings $settings): ConfigDirectorClient
    {
        $client = ConfigDirector::createClient(
            serverSdkKey: $settings->serverSdkKey,
            metadata: new Metadata(appName: 'slim-sample', appVersion: '1.0.0'),
            connection: $settings->connection,
            logLevel: $settings->logLevel,
        );
        $client->initialize();

        return $client;
    }

    private static function notFoundResponse(ResponseFactoryInterface $responseFactory): ResponseInterface
    {
        return JsonResponse::withBody(
            $responseFactory->createResponse(404),
            ['error' => 'Not found. Try GET /configs'],
        );
    }
}
