<?php

declare(strict_types=1);

namespace App\Tests;

use App\ConfigDirectorSettings;
use App\SampleApplication;
use App\Tests\Support\SdkServerOfTheSampleTests;
use ConfigDirector\ConfigDirectorClient;
use ConfigDirector\ConfigEvaluatedEvent;
use ConfigDirector\Context;
use ConfigDirector\EvaluationReason;
use DI\Container;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;

final class SampleApplicationTest extends TestCase
{
    private static ?SdkServerOfTheSampleTests $sdkServer = null;

    #[\Override]
    public static function setUpBeforeClass(): void
    {
        self::$sdkServer = SdkServerOfTheSampleTests::start();
    }

    #[\Override]
    public static function tearDownAfterClass(): void
    {
        self::$sdkServer?->stop();
        self::$sdkServer = null;
    }

    public function testConfigsReturnsTheFiveConfigsAsServed(): void
    {
        $app = SampleApplication::create(self::settingsOfTheSdkServer());
        $reasonsByConfigKey = [];
        self::clientIn($app)->on(
            'configEvaluated',
            static function (ConfigEvaluatedEvent $event) use (&$reasonsByConfigKey): void {
                $reasonsByConfigKey[$event->evaluation->key] = $event->evaluation->reason;
            },
        );

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
            json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR),
        );
        self::assertSame(
            [
                'temporary-feature-flag' => EvaluationReason::FoundMatch,
                'permanent-kill-switch' => EvaluationReason::FoundMatch,
                'integer-config' => EvaluationReason::FoundMatch,
                'day-of-the-week-config' => EvaluationReason::FoundMatch,
                'json-value-config' => EvaluationReason::FoundMatch,
            ],
            $reasonsByConfigKey,
        );
    }

    public function testTheQueryStringBecomesTheContextOfEveryEvaluation(): void
    {
        $app = SampleApplication::create(self::settingsOfTheSdkServer());
        $contextsByConfigKey = [];
        self::clientIn($app)->on(
            'configEvaluated',
            static function (ConfigEvaluatedEvent $event) use (&$contextsByConfigKey): void {
                $contextsByConfigKey[$event->evaluation->key] = $event->evaluation->context;
            },
        );

        self::get($app, '/configs?id=user-123&kind=organization&name=Ada&anonymous=true&plan=pro&region=eu');

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
        $app = SampleApplication::create(self::settingsOfTheSdkServer(logLevel: 'debug'));

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

    private static function settingsOfTheSdkServer(?string $logLevel = null): ConfigDirectorSettings
    {
        if (self::$sdkServer === null) {
            throw new \LogicException('The SDK server of the sample tests is not running.');
        }

        return self::$sdkServer->settings($logLevel);
    }

    /**
     * @param App<Container> $app
     */
    private static function clientIn(App $app): ConfigDirectorClient
    {
        $client = $app->getContainer()->get(ConfigDirectorClient::class);
        self::assertInstanceOf(ConfigDirectorClient::class, $client);

        return $client;
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

    private static function errorIn(ResponseInterface $response): string
    {
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsString($body['error'] ?? null);

        return $body['error'];
    }
}
