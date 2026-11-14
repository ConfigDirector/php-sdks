<?php

declare(strict_types=1);

namespace Tests\Feature;

use ConfigDirector\ConfigDirectorClient;
use ConfigDirector\ConfigDirectorValidationException;
use ConfigDirector\ConfigEvaluatedEvent;
use ConfigDirector\Context;
use ConfigDirector\EvaluationReason;
use ConfigDirector\Testing\ConfigDirectorTesting;
use ConfigDirector\Testing\TestClient;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Testing\TestResponse;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Response;

final class ConfigsRouteTest extends TestCase
{
    private string $fileOfTheConfigDirectorLog = '';

    #[\Override]
    protected function tearDown(): void
    {
        parent::tearDown();
        if ($this->fileOfTheConfigDirectorLog !== '' && is_file($this->fileOfTheConfigDirectorLog)) {
            unlink($this->fileOfTheConfigDirectorLog);
        }
    }

    public function testConfigsReturnsTheFiveConfigsAsServed(): void
    {
        $testClient = $this->initializedTestClientServingTheFiveConfigs();
        $reasonsByConfigKey = [];
        $testClient->client->on(
            'configEvaluated',
            static function (ConfigEvaluatedEvent $event) use (&$reasonsByConfigKey): void {
                $reasonsByConfigKey[$event->evaluation->key] = $event->evaluation->reason;
            },
        );

        $response = $this->get('/configs');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/json');
        self::assertSame(
            [
                'temporary-feature-flag' => false,
                'permanent-kill-switch' => true,
                'integer-config' => 42,
                'day-of-the-week-config' => 'Tuesday',
                'json-value-config' => ['greeting' => 'hello', 'sizes' => [1, 2]],
            ],
            $response->json(),
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

    public function testConfigsFollowAValueChangedAfterTheFirstRequest(): void
    {
        $testClient = $this->initializedTestClientServingTheFiveConfigs();
        $this->get('/configs');

        $testClient->setValue('integer-config', 7);
        $response = $this->get('/configs');

        $response->assertJsonPath('integer-config', 7);
    }

    public function testConfigsReturnTheInCodeDefaultValuesWhenInitializationFails(): void
    {
        $testClient = ConfigDirectorTesting::createTestClient(
            values: ['integer-config' => 42],
            logger: new NullLogger(),
        );
        $testClient->failInitialization();
        $testClient->client->initialize();
        $this->instance(ConfigDirectorClient::class, $testClient->client);

        $response = $this->get('/configs');

        self::assertSame(
            [
                'temporary-feature-flag' => true,
                'permanent-kill-switch' => false,
                'integer-config' => 10,
                'day-of-the-week-config' => 'Friday',
                'json-value-config' => [],
            ],
            $response->json(),
        );
    }

    public function testTheQueryStringBecomesTheContextOfEveryEvaluation(): void
    {
        $testClient = $this->initializedTestClientServingTheFiveConfigs();
        $contextsByConfigKey = [];
        $testClient->client->on(
            'configEvaluated',
            static function (ConfigEvaluatedEvent $event) use (&$contextsByConfigKey): void {
                $contextsByConfigKey[$event->evaluation->key] = $event->evaluation->context;
            },
        );

        $this->get('/configs?id=user-123&kind=organization&name=Ada&anonymous=true&plan=pro&region=eu');

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

    public function testEachRequestGetsOneInitializedClientOfItsOwn(): void
    {
        config(['configdirector.mode' => 'offline']);

        $clientOfTheFirstRequest = $this->app->make(ConfigDirectorClient::class);
        $clientReadAgainInTheFirstRequest = $this->app->make(ConfigDirectorClient::class);
        $this->app->forgetScopedInstances();
        $clientOfTheNextRequest = $this->app->make(ConfigDirectorClient::class);

        self::assertInstanceOf(ConfigDirectorClient::class, $clientOfTheFirstRequest);
        self::assertTrue($clientOfTheFirstRequest->isReady());
        self::assertSame($clientOfTheFirstRequest, $clientReadAgainInTheFirstRequest);
        self::assertNotSame($clientOfTheFirstRequest, $clientOfTheNextRequest);
    }

    public function testTheSdkWritesThroughTheConfigDirectorLogChannel(): void
    {
        $this->fileOfTheConfigDirectorLog = (string) tempnam(sys_get_temp_dir(), 'configdirector-log-');
        config([
            'configdirector.mode' => 'offline',
            'logging.channels.configdirector.level' => 'debug',
            'logging.channels.configdirector.handler_with.stream' => $this->fileOfTheConfigDirectorLog,
        ]);

        $this->get('/configs');

        self::assertMatchesRegularExpression(
            '/DEBUG.*integer-config/',
            (string) file_get_contents($this->fileOfTheConfigDirectorLog),
        );
    }

    public function testABaseUrlTheSdkRefusesFailsTheRequestAndIsReportedWithTheUrl(): void
    {
        Exceptions::fake();
        config(['configdirector.base_url' => 'ftp://proxy.internal']);

        $response = $this->get('/configs');

        $response->assertStatus(500);
        $response->assertHeader('Content-Type', 'application/json');
        Exceptions::assertReported(
            static fn(ConfigDirectorValidationException $exception): bool => str_contains(
                $exception->getMessage(),
                'ftp://proxy.internal',
            ),
        );
    }

    public function testAnUnknownRouteAnswersNotFoundWithAHintToTheConfigsRoute(): void
    {
        $response = $this->get('/other');

        self::assertNotFoundWithAHintToTheConfigsRoute($response);
    }

    public function testAnotherMethodOnTheConfigsRouteAnswersNotFoundWithAHintToTheConfigsRoute(): void
    {
        $response = $this->post('/configs');

        self::assertNotFoundWithAHintToTheConfigsRoute($response);
    }

    private function initializedTestClientServingTheFiveConfigs(): TestClient
    {
        $testClient = ConfigDirectorTesting::createTestClient(values: [
            'temporary-feature-flag' => false,
            'permanent-kill-switch' => true,
            'integer-config' => 42,
            'day-of-the-week-config' => 'Tuesday',
            'json-value-config' => ['greeting' => 'hello', 'sizes' => [1, 2]],
        ]);
        $testClient->client->initialize();
        $this->instance(ConfigDirectorClient::class, $testClient->client);

        return $testClient;
    }

    /**
     * @param TestResponse<Response> $response
     */
    private static function assertNotFoundWithAHintToTheConfigsRoute(TestResponse $response): void
    {
        $response->assertNotFound();
        $response->assertHeader('Content-Type', 'application/json');
        $error = $response->json('error');
        self::assertIsString($error);
        self::assertStringContainsString('GET /configs', $error);
    }
}
