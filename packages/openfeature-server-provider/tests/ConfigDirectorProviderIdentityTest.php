<?php

declare(strict_types=1);

namespace ConfigDirector\OpenFeature\Tests;

use ConfigDirector\ConnectionOptions;
use ConfigDirector\OpenFeature\ConfigDirectorProvider;
use ConfigDirector\OpenFeature\Tests\Support\PhpProcess;
use ConfigDirector\OpenFeature\Tests\Support\ScriptedSdkServer;
use ConfigDirector\OpenFeature\Tests\Support\TemporaryFolder;
use OpenFeature\isolated\OpenFeatureAPIFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ConfigDirectorProviderIdentityTest extends TestCase
{
    private string $storagePath;

    private ScriptedSdkServer $server;

    #[\Override]
    protected function setUp(): void
    {
        $this->storagePath = TemporaryFolder::create();
        $this->server = ScriptedSdkServer::answeringEveryRequestWith(200, [
            'payloadVersion' => 1,
            'environmentId' => '3f1b6c0a-52d4-4e8b-9a7c-0d2e4f6a8b10',
            'projectId' => '9c4d2e7f-1a3b-4c5d-8e6f-7a8b9c0d1e20',
            'configs' => new \stdClass(),
            'segments' => new \stdClass(),
            'kind' => 'full',
            'timestamp' => '2026-11-15T12:00:00.000000Z',
        ]);
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->server->stop();
        TemporaryFolder::remove($this->storagePath);
    }

    public function testThePollNamesTheProvider(): void
    {
        $this->providerOverTheScriptedServer();

        $metaContext = $this->metaContextOfThePoll();

        self::assertSame('php-openfeature-server-provider', $metaContext['sdkName'] ?? null);
    }

    public function testThePollReportsTheVersionOfTheProviderPackage(): void
    {
        $this->createAProviderOfVersion987InAnotherProcess();

        $metaContext = $this->metaContextOfThePoll();

        self::assertSame('9.8.7', $metaContext['sdkVersion'] ?? null);
    }

    public function testTheUserAgentNamesTheProviderAndTheVersionOfItsPackage(): void
    {
        $this->createAProviderOfVersion987InAnotherProcess();

        $userAgent = $this->server->requestsReceived()[0]['headers']['user-agent'] ?? null;

        self::assertSame('php-openfeature-server-provider/9.8.7', $userAgent);
    }

    public function testTheProviderNamesItselfToOpenFeature(): void
    {
        $api = OpenFeatureAPIFactory::createAPI();
        $api->setProvider($this->providerOverTheScriptedServer());

        $name = $api->getProviderMetadata()->getName();

        self::assertSame('ConfigDirectorProvider', $name);
    }

    private function providerOverTheScriptedServer(): ConfigDirectorProvider
    {
        return new ConfigDirectorProvider(
            serverSdkKey: 'server-sdk-key',
            connection: new ConnectionOptions(url: $this->server->url, storagePath: $this->storagePath),
            logger: new NullLogger(),
        );
    }

    private function createAProviderOfVersion987InAnotherProcess(): void
    {
        $exitCode = PhpProcess::runToItsEnd(
            __DIR__ . '/Processes/create-a-provider-of-version-9.8.7.php',
            [$this->server->url, $this->storagePath],
        );
        self::assertSame(0, $exitCode);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function metaContextOfThePoll(): array
    {
        $requests = $this->server->requestsReceived();
        self::assertSame(['/server/polling/v1'], array_column($requests, 'path'));
        $body = $requests[0]['body'];
        self::assertIsArray($body);
        self::assertIsArray($body['metaContext'] ?? null);

        return $body['metaContext'];
    }
}
