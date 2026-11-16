<?php

declare(strict_types=1);

namespace App\Tests;

use App\ConfigDirectorSettings;
use App\SampleApplication;
use ConfigDirector\ClientHooks;
use ConfigDirector\ConfigEvaluatedEvent;
use ConfigDirector\ConnectionMode;
use ConfigDirector\ConnectionOptions;
use ConfigDirector\Context;
use ConfigDirector\OpenFeature\ConfigDirectorProvider;
use DI\Container;
use OpenFeature\interfaces\provider\Provider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;

final class SampleApplicationTest extends TestCase
{
    public function testConfigsReturnsTheFiveConfigsAsServed(): void
    {
        $app = self::appReadingThrough(self::offlineProviderServingTheFiveConfigs());

        $response = self::get($app, '/configs?id=user-123&plan=pro');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame(
            [
                'temporary-feature-flag' => false,
                'permanent-kill-switch' => true,
                'integer-config' => 42,
                'day-of-the-week-config' => 'Tuesday',
                'json-value-config' => ['greeting' => 'hello', 'sizes' => [1, 2]],
            ],
            self::bodyOf($response),
        );
    }

    public function testConfigsReturnTheInCodeDefaultValuesWhenNoConfigIsServed(): void
    {
        $provider = new ConfigDirectorProvider(
            connection: new ConnectionOptions(mode: ConnectionMode::Offline, values: []),
        );

        $response = self::get(self::appReadingThrough($provider), '/configs?id=user-123&plan=pro');

        self::assertSame(
            [
                'temporary-feature-flag' => true,
                'permanent-kill-switch' => false,
                'integer-config' => 10,
                'day-of-the-week-config' => 'Friday',
                'json-value-config' => [],
            ],
            self::bodyOf($response),
        );
    }

    public function testTheQueryStringBecomesTheContextOfEveryEvaluation(): void
    {
        $contextsByConfigKey = [];
        $provider = self::offlineProviderServingTheFiveConfigs(new ClientHooks(
            configEvaluated: static function (ConfigEvaluatedEvent $event) use (&$contextsByConfigKey): void {
                $contextsByConfigKey[$event->evaluation->key] = $event->evaluation->context;
            },
        ));

        self::get(
            self::appReadingThrough($provider),
            '/configs?id=user-123&kind=organization&name=Ada&anonymous=true&plan=pro&region=eu',
        );

        $ada = new Context(
            id: 'user-123',
            kind: 'organization',
            name: 'Ada',
            traits: ['plan' => 'pro', 'region' => 'eu'],
            anonymous: true,
        );
        self::assertEquals(
            [
                'temporary-feature-flag' => $ada,
                'permanent-kill-switch' => $ada,
                'integer-config' => $ada,
                'day-of-the-week-config' => $ada,
                'json-value-config' => $ada,
            ],
            $contextsByConfigKey,
        );
    }

    public function testTheDebugLogLevelMakesTheSdkWriteEachEvaluation(): void
    {
        $app = SampleApplication::create(
            ConfigDirectorSettings::fromEnvironment([
                'CONFIGDIRECTOR_MODE' => 'offline',
                'CONFIGDIRECTOR_LOG_LEVEL' => 'debug',
            ]),
        );

        $this->expectOutputRegex('/debug.*integer-config/');
        self::get($app, '/configs');
    }

    public function testABaseUrlTheSdkRefusesFailsTheRequestWithTheUrlInTheLog(): void
    {
        $app = SampleApplication::create(
            ConfigDirectorSettings::fromEnvironment(['CONFIGDIRECTOR_BASE_URL' => 'ftp://proxy.internal']),
        );

        $this->expectOutputRegex('#ftp://proxy\.internal#');
        $response = self::get($app, '/configs');

        self::assertSame(500, $response->getStatusCode());
    }

    public function testAnUnknownRouteAnswersNotFoundWithAHintToTheConfigsRoute(): void
    {
        $app = SampleApplication::create(ConfigDirectorSettings::fromEnvironment([]));

        $response = self::get($app, '/other');

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('GET /configs', self::errorIn($response));
    }

    public function testAnotherMethodOnTheConfigsRouteAnswersNotFoundWithAHintToTheConfigsRoute(): void
    {
        $app = SampleApplication::create(ConfigDirectorSettings::fromEnvironment([]));

        $response = self::request($app, 'POST', '/configs');

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('GET /configs', self::errorIn($response));
    }

    private static function offlineProviderServingTheFiveConfigs(?ClientHooks $hooks = null): ConfigDirectorProvider
    {
        return new ConfigDirectorProvider(
            connection: new ConnectionOptions(
                mode: ConnectionMode::Offline,
                values: [
                    'temporary-feature-flag' => false,
                    'permanent-kill-switch' => true,
                    'integer-config' => 42,
                    'day-of-the-week-config' => 'Tuesday',
                    'json-value-config' => ['greeting' => 'hello', 'sizes' => [1, 2]],
                ],
            ),
            hooks: $hooks,
        );
    }

    /**
     * @return App<Container>
     */
    private static function appReadingThrough(Provider $provider): App
    {
        $app = SampleApplication::create(ConfigDirectorSettings::fromEnvironment([]));
        $app->getContainer()->set(Provider::class, $provider);

        return $app;
    }

    /**
     * @param App<Container> $app
     */
    private static function get(App $app, string $target): ResponseInterface
    {
        return self::request($app, 'GET', $target);
    }

    /**
     * @param App<Container> $app
     */
    private static function request(App $app, string $method, string $target): ResponseInterface
    {
        return $app->handle((new ServerRequestFactory())->createServerRequest($method, $target));
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function bodyOf(ResponseInterface $response): array
    {
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($body);

        return $body;
    }

    private static function errorIn(ResponseInterface $response): string
    {
        $error = self::bodyOf($response)['error'] ?? null;
        self::assertIsString($error);

        return $error;
    }
}
