<?php

declare(strict_types=1);

namespace ConfigDirector\OpenFeature\Tests;

use ConfigDirector\ConfigDirectorInitializationException;
use ConfigDirector\ConfigDirectorValidationException;
use ConfigDirector\ConnectionMode;
use ConfigDirector\ConnectionOptions;
use ConfigDirector\OpenFeature\ConfigDirectorProvider;
use ConfigDirector\OpenFeature\Tests\Support\ErrorOfTheEvaluation;
use ConfigDirector\OpenFeature\Tests\Support\ScriptedSdkServer;
use ConfigDirector\OpenFeature\Tests\Support\StackTraceArguments;
use ConfigDirector\OpenFeature\Tests\Support\TemporaryFolder;
use OpenFeature\interfaces\provider\Reason;
use OpenFeature\isolated\OpenFeatureAPIFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ConfigDirectorProviderConstructionTest extends TestCase
{
    private string $storagePath;

    /** @var list<ScriptedSdkServer> */
    private array $servers = [];

    #[\Override]
    protected function setUp(): void
    {
        $this->storagePath = TemporaryFolder::create();
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->servers as $server) {
            $server->stop();
        }
        TemporaryFolder::remove($this->storagePath);
    }

    public function testAFlagOfAProviderThatReceivedNoValuesResolvesToTheInCodeDefaultValueWithProviderNotReady(): void
    {
        $server = $this->serverAnsweringEveryRequestWith(401, '{"message":"Unauthorized"}');
        $api = OpenFeatureAPIFactory::createAPI();
        $api->setProvider(new ConfigDirectorProvider(
            serverSdkKey: 'rejected-server-sdk-key',
            connection: new ConnectionOptions(url: $server->url, storagePath: $this->storagePath),
            logger: new NullLogger(),
        ));

        $details = $api->getClient(null, null)->getBooleanDetails('new-checkout', true);

        self::assertTrue($details->getValue());
        self::assertSame(Reason::ERROR, $details->getReason());
        self::assertNull($details->getVariant());
        self::assertSame('PROVIDER_NOT_READY', ErrorOfTheEvaluation::codeIn($details));
        self::assertNotSame('', ErrorOfTheEvaluation::messageIn($details));
    }

    public function testCreatingTheProviderAsksConfigDirectorForValuesBeforeTheFirstRead(): void
    {
        $server = $this->serverAnsweringEveryRequestWith(200, self::emptyServerSdkPayload());

        new ConfigDirectorProvider(
            serverSdkKey: 'server-sdk-key',
            connection: new ConnectionOptions(url: $server->url, storagePath: $this->storagePath),
            logger: new NullLogger(),
        );

        self::assertSame(['/server/polling/v1'], array_column($server->requestsReceived(), 'path'));
    }

    public function testAnOfflineProviderServesItsValuesWithoutAServerSdkKey(): void
    {
        $api = OpenFeatureAPIFactory::createAPI();
        $api->setProvider(new ConfigDirectorProvider(
            connection: new ConnectionOptions(mode: ConnectionMode::Offline, values: ['new-checkout' => true]),
        ));

        $value = $api->getClient(null, null)->getBooleanValue('new-checkout', false);

        self::assertTrue($value);
    }

    public function testCreatingAnOfflineProviderWhoseFileCannotBeReadThrows(): void
    {
        $this->expectException(ConfigDirectorInitializationException::class);

        new ConfigDirectorProvider(
            connection: new ConnectionOptions(
                mode: ConnectionMode::Offline,
                file: $this->storagePath . DIRECTORY_SEPARATOR . 'missing-values.json',
            ),
            logger: new NullLogger(),
        );
    }

    public function testCreatingAProviderWithoutAServerSdkKeyOutsideOfflineModeThrows(): void
    {
        $this->expectException(ConfigDirectorValidationException::class);

        new ConfigDirectorProvider(logger: new NullLogger());
    }

    public function testARejectedUrlKeepsTheServerSdkKeyOutOfTheStackTrace(): void
    {
        try {
            new ConfigDirectorProvider(
                serverSdkKey: 'server-sdk-key-to-keep-secret',
                connection: new ConnectionOptions(url: 'sdk-proxy.example.com'),
                logger: new NullLogger(),
            );
            self::fail('The provider accepted a URL without a scheme.');
        } catch (ConfigDirectorValidationException $rejectedUrl) {
        }

        self::assertSame([], StackTraceArguments::holding('server-sdk-key-to-keep-secret', $rejectedUrl));
    }

    public function testADumpOfTheProviderDoesNotShowTheServerSdkKey(): void
    {
        $server = $this->serverAnsweringEveryRequestWith(200, self::emptyServerSdkPayload());
        $provider = new ConfigDirectorProvider(
            serverSdkKey: 'server-sdk-key-to-keep-secret',
            connection: new ConnectionOptions(url: $server->url, storagePath: $this->storagePath),
            logger: new NullLogger(),
        );

        ob_start();
        var_dump($provider);
        $dumps = [print_r($provider, true), var_export($provider, true), (string) ob_get_clean()];

        self::assertSame(
            [],
            array_filter($dumps, static fn(string $dump): bool => str_contains($dump, 'server-sdk-key-to-keep-secret')),
        );
    }

    /**
     * @param array<string, mixed>|string $body
     */
    private function serverAnsweringEveryRequestWith(int $status, array|string $body): ScriptedSdkServer
    {
        $server = ScriptedSdkServer::answeringEveryRequestWith($status, $body);
        $this->servers[] = $server;

        return $server;
    }

    /**
     * @return array<string, mixed>
     */
    private static function emptyServerSdkPayload(): array
    {
        return [
            'payloadVersion' => 1,
            'environmentId' => '3f1b6c0a-52d4-4e8b-9a7c-0d2e4f6a8b10',
            'projectId' => '9c4d2e7f-1a3b-4c5d-8e6f-7a8b9c0d1e20',
            'configs' => new \stdClass(),
            'segments' => new \stdClass(),
            'kind' => 'full',
            'timestamp' => '2026-11-15T12:00:00.000000Z',
        ];
    }
}
