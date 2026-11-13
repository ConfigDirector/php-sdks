<?php

declare(strict_types=1);

namespace ConfigDirector\Tests;

use ConfigDirector\ClientHooks;
use ConfigDirector\ConfigDirector;
use ConfigDirector\ConfigDirectorValidationException;
use ConfigDirector\ConnectionOptions;
use ConfigDirector\Context;
use ConfigDirector\Tests\Support\RecordingLogger;
use ConfigDirector\Tests\Support\SampleConfigsTestCase;
use Psr\Log\LogLevel;

final class DefaultLoggerTest extends SampleConfigsTestCase
{
    public function testAWarningIsWrittenThroughErrorLog(): void
    {
        $this->expectOutputRegex('/^\[ConfigDirector:php-server-sdk\] warning: [^\r\n{]* 30 [^\r\n{]*\R$/');

        ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            connection: new ConnectionOptions(pollingInterval: 30),
        );
    }

    public function testAnErrorIsWrittenThroughErrorLog(): void
    {
        $client = ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            connection: self::connectionToTheSdkServer(),
        );
        $client->initialize();

        $this->expectOutputRegex('/^\[ConfigDirector:php-server-sdk\] error: [^\r\n{]*Device[^\r\n{]*\R$/');

        $client->getValue('integer-config', 10, new Context(id: 'device-1', kind: 'Device'));
    }

    public function testDebugMessagesAreNotWrittenByDefault(): void
    {
        $client = ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            connection: self::connectionToTheSdkServer(),
        );
        $client->initialize();

        $this->expectOutputString('');

        $client->getValue('integer-config', 10);
    }

    public function testTheDebugLogLevelWritesDebugMessages(): void
    {
        $client = ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            connection: self::connectionToTheSdkServer(),
            logLevel: LogLevel::DEBUG,
        );
        $client->initialize();

        $this->expectOutputRegex(
            '/\[ConfigDirector:php-server-sdk\] debug: [^\r\n{]*integer-config[^\r\n{]*42[^\r\n{]*\R$/',
        );

        $client->getValue('integer-config', 10);
    }

    public function testADebugMessageShowsAnArrayValueAsJson(): void
    {
        $client = ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            connection: self::connectionToTheSdkServer(),
            logLevel: LogLevel::DEBUG,
        );
        $client->initialize();

        $this->expectOutputRegex('/' . preg_quote('{"greeting":"hello","sizes":[1,2]}', '/') . '[^\r\n]*\R$/');

        $client->getValue('json-value-config', []);
    }

    public function testTheErrorLogLevelHidesWarnings(): void
    {
        $this->expectOutputString('');

        ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            connection: new ConnectionOptions(pollingInterval: 30),
            logLevel: LogLevel::ERROR,
        );
    }

    public function testTheErrorLogLevelStillWritesErrors(): void
    {
        $client = ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            connection: self::connectionToTheSdkServer(),
            logLevel: LogLevel::ERROR,
        );
        $client->initialize();

        $this->expectOutputRegex('/^\[ConfigDirector:php-server-sdk\] error: [^\r\n]*\R$/');

        $client->getValue('integer-config', 10, new Context(id: 'device-1', kind: 'Device'));
    }

    public function testALineBreakInAnInvalidKindStaysOnOneLine(): void
    {
        $client = ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            connection: self::connectionToTheSdkServer(),
        );
        $client->initialize();

        $this->expectOutputRegex('/^[^\r\n]*Device {2}forged line[^\r\n]*\R$/');

        $client->getValue('integer-config', 10, new Context(id: 'device-1', kind: "Device\r\nforged line"));
    }

    public function testAnErrorEndsWithTheClassAndMessageOfItsException(): void
    {
        $client = ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            connection: self::connectionToTheSdkServer(),
            hooks: new ClientHooks(configEvaluated: static function (): void {
                throw new \RuntimeException('The handler failed');
            }),
        );
        $client->initialize();

        $this->expectOutputRegex(
            '/^\[ConfigDirector:php-server-sdk\] error: [^\r\n]*configEvaluated[^\r\n]* RuntimeException: The handler failed\R$/',
        );

        $client->getValue('integer-config', 10);
    }

    public function testALineBreakInAnExceptionMessageStaysOnOneLine(): void
    {
        $client = ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            connection: self::connectionToTheSdkServer(),
            hooks: new ClientHooks(configEvaluated: static function (): void {
                throw new \RuntimeException("The handler failed\r\nforged line");
            }),
        );
        $client->initialize();

        $this->expectOutputRegex('/^[^\r\n]*The handler failed {2}forged line\R$/');

        $client->getValue('integer-config', 10);
    }

    public function testALogLevelThatIsNotAPsrLevelIsRejected(): void
    {
        $this->expectException(ConfigDirectorValidationException::class);

        ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            connection: self::connectionToTheSdkServer(),
            logLevel: 'warn',
        );
    }

    public function testTheLogLevelHasNoEffectWhenALoggerIsGiven(): void
    {
        $logger = new RecordingLogger();

        $this->expectOutputString('');

        ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            connection: new ConnectionOptions(pollingInterval: 30),
            logger: $logger,
            logLevel: LogLevel::ERROR,
        );

        self::assertCount(1, $logger->contextsLoggedAt(LogLevel::WARNING));
    }
}
