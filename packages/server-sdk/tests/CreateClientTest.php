<?php

declare(strict_types=1);

namespace ConfigDirector\Tests;

use ConfigDirector\ConfigDirector;
use ConfigDirector\ConfigDirectorException;
use ConfigDirector\ConfigDirectorValidationException;
use ConfigDirector\ConnectionMode;
use ConfigDirector\ConnectionOptions;
use ConfigDirector\TelemetryOptions;
use ConfigDirector\Tests\Support\RecordingLogger;
use PHPUnit\Framework\Attributes\DoesNotPerformAssertions;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

final class CreateClientTest extends TestCase
{
    public function testAClientWithoutAServerSdkKeyIsRejected(): void
    {
        $this->expectException(ConfigDirectorValidationException::class);

        ConfigDirector::createClient(logger: new RecordingLogger());
    }

    public function testAClientWithABlankServerSdkKeyIsRejected(): void
    {
        $this->expectException(ConfigDirectorValidationException::class);

        ConfigDirector::createClient(serverSdkKey: "  \t", logger: new RecordingLogger());
    }

    #[DoesNotPerformAssertions]
    public function testAnOfflineClientNeedsNoServerSdkKey(): void
    {
        ConfigDirector::createClient(
            connection: new ConnectionOptions(mode: ConnectionMode::Offline),
            logger: new RecordingLogger(),
        );
    }

    public function testAValidationExceptionIsAConfigDirectorException(): void
    {
        $this->expectException(ConfigDirectorException::class);

        ConfigDirector::createClient(logger: new RecordingLogger());
    }

    public function testAUrlWithoutASchemeIsRejected(): void
    {
        $this->expectException(ConfigDirectorValidationException::class);

        ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            connection: new ConnectionOptions(url: 'sdk-proxy.example.com'),
            logger: new RecordingLogger(),
        );
    }

    public function testAUrlThatIsNotHttpOrHttpsIsRejected(): void
    {
        $this->expectException(ConfigDirectorValidationException::class);

        ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            connection: new ConnectionOptions(url: 'ftp://sdk-proxy.example.com'),
            logger: new RecordingLogger(),
        );
    }

    public function testAUrlWithASpaceIsRejected(): void
    {
        $this->expectException(ConfigDirectorValidationException::class);

        ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            connection: new ConnectionOptions(url: 'http://sdk proxy.example.com'),
            logger: new RecordingLogger(),
        );
    }

    #[DoesNotPerformAssertions]
    public function testAnHttpProxyUrlIsAccepted(): void
    {
        ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            connection: new ConnectionOptions(url: 'http://sdk_proxy:8080/configdirector'),
            logger: new RecordingLogger(),
        );
    }

    public function testAnEventQueueLimitOfFiftyIsRejected(): void
    {
        $this->expectException(ConfigDirectorValidationException::class);

        ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            logger: new RecordingLogger(),
            telemetry: new TelemetryOptions(eventQueueLimit: 50),
        );
    }

    public function testAnEventQueueLimitOfNinetyNineIsRejected(): void
    {
        $this->expectException(ConfigDirectorValidationException::class);

        ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            logger: new RecordingLogger(),
            telemetry: new TelemetryOptions(eventQueueLimit: 99),
        );
    }

    #[DoesNotPerformAssertions]
    public function testAnEventQueueLimitOfOneHundredIsAccepted(): void
    {
        ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            logger: new RecordingLogger(),
            telemetry: new TelemetryOptions(eventQueueLimit: 100),
        );
    }

    #[DoesNotPerformAssertions]
    public function testAnEventQueueLimitOfOneHundredThousandIsAccepted(): void
    {
        ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            logger: new RecordingLogger(),
            telemetry: new TelemetryOptions(eventQueueLimit: 100_000),
        );
    }

    public function testAnEventQueueLimitAboveOneHundredThousandIsRejected(): void
    {
        $this->expectException(ConfigDirectorValidationException::class);

        ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            logger: new RecordingLogger(),
            telemetry: new TelemetryOptions(eventQueueLimit: 100_001),
        );
    }

    public function testAFlushIntervalOfZeroIsRejected(): void
    {
        $this->expectException(ConfigDirectorValidationException::class);

        ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            logger: new RecordingLogger(),
            telemetry: new TelemetryOptions(flushInterval: 0),
        );
    }

    public function testANegativeFlushIntervalIsRejected(): void
    {
        $this->expectException(ConfigDirectorValidationException::class);

        ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            logger: new RecordingLogger(),
            telemetry: new TelemetryOptions(flushInterval: -1),
        );
    }

    public function testANegativeTimeoutIsRejected(): void
    {
        $this->expectException(ConfigDirectorValidationException::class);

        ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            connection: new ConnectionOptions(timeout: -1),
            logger: new RecordingLogger(),
        );
    }

    public function testATimeoutOfZeroIsRejected(): void
    {
        $this->expectException(ConfigDirectorValidationException::class);

        ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            connection: new ConnectionOptions(timeout: 0),
            logger: new RecordingLogger(),
        );
    }

    public function testAPollingIntervalOfThirtySecondsLogsOneWarningNamingTheMinimumOfSixty(): void
    {
        $logger = new RecordingLogger();

        ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            connection: new ConnectionOptions(pollingInterval: 30),
            logger: $logger,
        );

        self::assertSame(
            [['configuredSeconds' => 30, 'minimumSeconds' => 60]],
            $logger->contextsLoggedAt(LogLevel::WARNING),
        );
    }

    public function testAPollingIntervalOfSixtySecondsLogsNoWarning(): void
    {
        $logger = new RecordingLogger();

        ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            connection: new ConnectionOptions(pollingInterval: 60),
            logger: $logger,
        );

        self::assertSame([], $logger->contextsLoggedAt(LogLevel::WARNING));
    }

    public function testTheDefaultOptionsLogNoWarning(): void
    {
        $logger = new RecordingLogger();

        ConfigDirector::createClient(serverSdkKey: 'server-sdk-key', logger: $logger);

        self::assertSame([], $logger->contextsLoggedAt(LogLevel::WARNING));
    }

    public function testAnOfflineClientIgnoresThePollingInterval(): void
    {
        $logger = new RecordingLogger();

        ConfigDirector::createClient(
            connection: new ConnectionOptions(mode: ConnectionMode::Offline, pollingInterval: 30),
            logger: $logger,
        );

        self::assertSame([], $logger->contextsLoggedAt(LogLevel::WARNING));
    }
}
