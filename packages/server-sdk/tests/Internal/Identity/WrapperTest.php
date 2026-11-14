<?php

declare(strict_types=1);

namespace ConfigDirector\Tests\Internal\Identity;

use ConfigDirector\ConfigDirectorValidationException;
use ConfigDirector\ConnectionOptions;
use ConfigDirector\Internal\Identity\Wrapper;
use ConfigDirector\Tests\Support\PhpProcess;
use ConfigDirector\Tests\Support\RecordingLogger;
use ConfigDirector\Tests\Support\ScriptedSdkServer;
use ConfigDirector\Tests\Support\StackTraceArguments;
use ConfigDirector\Tests\Support\TemporaryFolder;
use PHPUnit\Framework\TestCase;

final class WrapperTest extends TestCase
{
    private string $storagePath;

    private ScriptedSdkServer $server;

    #[\Override]
    protected function setUp(): void
    {
        $this->storagePath = TemporaryFolder::create();
        $this->server = ScriptedSdkServer::answering([
            ['status' => 200, 'body' => ['configs' => [], 'kind' => 'full']],
        ]);
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->server->stop();
        TemporaryFolder::remove($this->storagePath);
    }

    public function testAClientOfTheOpenFeatureProviderPollsUnderTheSdkNameOfTheProvider(): void
    {
        $client = Wrapper::OpenFeatureServerProvider->createClient(
            serverSdkKey: 'server-sdk-key',
            connection: new ConnectionOptions(url: $this->server->url, storagePath: $this->storagePath),
            logger: new RecordingLogger(),
        );

        $client->initialize();

        self::assertSame('php-openfeature-server-provider', $this->metaContextOfThePoll()['sdkName'] ?? null);
    }

    public function testAClientOfTheOpenFeatureProviderNamesTheProviderInItsUserAgent(): void
    {
        $client = Wrapper::OpenFeatureServerProvider->createClient(
            serverSdkKey: 'server-sdk-key',
            connection: new ConnectionOptions(url: $this->server->url, storagePath: $this->storagePath),
            logger: new RecordingLogger(),
        );

        $client->initialize();

        self::assertSame(
            'php-openfeature-server-provider/0.0.0-dev',
            $this->server->requestsReceived()[0]['headers']['user-agent'],
        );
    }

    public function testAClientOfTheOpenFeatureProviderReportsTheVersionOfTheProviderPackage(): void
    {
        PhpProcess::running(
            __DIR__ . '/Processes/poll-as-the-openfeature-provider.php',
            [$this->server->url, $this->storagePath],
        )->finished();

        self::assertSame('9.8.7', $this->metaContextOfThePoll()['sdkVersion'] ?? null);
    }

    public function testAClientOfTheOpenFeatureProviderReportsADevelopmentVersionWithoutTheProviderPackage(): void
    {
        $client = Wrapper::OpenFeatureServerProvider->createClient(
            serverSdkKey: 'server-sdk-key',
            connection: new ConnectionOptions(url: $this->server->url, storagePath: $this->storagePath),
            logger: new RecordingLogger(),
        );

        $client->initialize();

        self::assertSame('0.0.0-dev', $this->metaContextOfThePoll()['sdkVersion'] ?? null);
    }

    public function testARejectedUrlKeepsTheServerSdkKeyOutOfTheStackTrace(): void
    {
        try {
            Wrapper::OpenFeatureServerProvider->createClient(
                serverSdkKey: 'server-sdk-key-to-keep-secret',
                connection: new ConnectionOptions(url: 'sdk-proxy.example.com'),
                logger: new RecordingLogger(),
            );
            self::fail('createClient() accepted a URL without a scheme.');
        } catch (ConfigDirectorValidationException $rejectedUrl) {
        }

        self::assertSame([], StackTraceArguments::holding('server-sdk-key-to-keep-secret', $rejectedUrl));
    }

    public function testTheOpenFeatureProviderIsTheOnlyIdentityAWrapperCanReport(): void
    {
        self::assertSame(['php-openfeature-server-provider'], array_column(Wrapper::cases(), 'value'));
    }

    /**
     * @return array<array-key, mixed>
     */
    private function metaContextOfThePoll(): array
    {
        $requests = $this->server->requestsReceived();
        self::assertCount(1, $requests);
        $body = $requests[0]['body'];
        self::assertIsArray($body);
        self::assertIsArray($body['metaContext'] ?? null);

        return $body['metaContext'];
    }
}
