<?php

declare(strict_types=1);

namespace App;

use ConfigDirector\Metadata;
use ConfigDirector\OpenFeature\ConfigDirectorProvider;
use DI\Container;
use OpenFeature\interfaces\flags\Client;
use OpenFeature\interfaces\provider\Provider;
use OpenFeature\OpenFeatureAPI;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Exception\HttpNotFoundException;
use Slim\Factory\AppFactory;

final class SampleApplication
{
    private const string APP_NAME = 'openfeature-slim-sample';

    private const string APP_VERSION = '1.0.0';

    private function __construct() {}

    /**
     * @return App<Container>
     */
    public static function create(ConfigDirectorSettings $settings): App
    {
        $container = new Container([
            Provider::class => fn(): Provider => self::configDirectorProvider($settings),
            Client::class => fn(Provider $provider): Client => self::clientAfterSettingTheProvider($provider),
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

    private static function configDirectorProvider(ConfigDirectorSettings $settings): ConfigDirectorProvider
    {
        return new ConfigDirectorProvider(
            serverSdkKey: $settings->serverSdkKey,
            metadata: new Metadata(appName: self::APP_NAME, appVersion: self::APP_VERSION),
            connection: $settings->connection,
            logLevel: $settings->logLevel,
        );
    }

    private static function clientAfterSettingTheProvider(Provider $provider): Client
    {
        $api = OpenFeatureAPI::getInstance();
        $api->setProvider($provider);

        return $api->getClient(self::APP_NAME, self::APP_VERSION);
    }

    private static function notFoundResponse(ResponseFactoryInterface $responseFactory): ResponseInterface
    {
        return JsonResponse::withBody(
            $responseFactory->createResponse(404),
            ['error' => 'Not found. Try GET /configs'],
        );
    }
}
