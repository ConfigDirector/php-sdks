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
use PHPUnit\Framework\Attributes\RequiresOperatingSystemFamily;
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

    public function testABlankStoragePathIsRejected(): void
    {
        $this->expectException(ConfigDirectorValidationException::class);

        ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            connection: new ConnectionOptions(storagePath: ''),
            logger: new RecordingLogger(),
        );
    }

    public function testARelativeStoragePathIsRejected(): void
    {
        $this->expectException(ConfigDirectorValidationException::class);

        ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            connection: new ConnectionOptions(storagePath: 'var/configdirector'),
            logger: new RecordingLogger(),
        );
    }

    #[DoesNotPerformAssertions]
    public function testAnAbsoluteStoragePathIsAccepted(): void
    {
        ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            connection: new ConnectionOptions(
                storagePath: sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'configdirector-storage-path',
            ),
            logger: new RecordingLogger(),
        );
    }

    #[DoesNotPerformAssertions]
    #[RequiresOperatingSystemFamily('Windows')]
    public function testANetworkPathIsAcceptedAsAStoragePathOnWindows(): void
    {
        ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            connection: new ConnectionOptions(storagePath: '\\\\file-server\\configdirector'),
            logger: new RecordingLogger(),
        );
    }

    #[RequiresOperatingSystemFamily('Windows')]
    public function testAPathWithoutADriveIsRejectedAsAStoragePathOnWindows(): void
    {
        $this->expectException(ConfigDirectorValidationException::class);

        ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            connection: new ConnectionOptions(storagePath: '\\configdirector'),
            logger: new RecordingLogger(),
        );
    }

    #[DoesNotPerformAssertions]
    public function testAnOfflineClientIgnoresTheStoragePath(): void
    {
        ConfigDirector::createClient(
            connection: new ConnectionOptions(mode: ConnectionMode::Offline, storagePath: 'var/configdirector'),
            logger: new RecordingLogger(),
        );
    }

    public function testAnOfflineClientWithAFileAndValuesIsRejected(): void
    {
        $this->expectException(ConfigDirectorValidationException::class);

        ConfigDirector::createClient(
            connection: new ConnectionOptions(
                mode: ConnectionMode::Offline,
                file: __DIR__ . '/flags.json',
                values: ['new-checkout' => true],
            ),
            logger: new RecordingLogger(),
        );
    }

    public function testValuesInPollingModeAreRejected(): void
    {
        $this->expectException(ConfigDirectorValidationException::class);

        ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            connection: new ConnectionOptions(values: ['new-checkout' => true]),
            logger: new RecordingLogger(),
        );
    }

    public function testEmptyValuesInPollingModeAreRejected(): void
    {
        $this->expectException(ConfigDirectorValidationException::class);

        ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            connection: new ConnectionOptions(values: []),
            logger: new RecordingLogger(),
        );
    }

    public function testAFileInPollingModeIsRejected(): void
    {
        $this->expectException(ConfigDirectorValidationException::class);

        ConfigDirector::createClient(
            serverSdkKey: 'server-sdk-key',
            connection: new ConnectionOptions(file: __DIR__ . '/flags.json'),
            logger: new RecordingLogger(),
        );
    }

    public function testAnInlineNullValueIsRejected(): void
    {
        $this->expectException(ConfigDirectorValidationException::class);

        ConfigDirector::createClient(
            // @phpstan-ignore argument.type
            connection: new ConnectionOptions(mode: ConnectionMode::Offline, values: ['new-checkout' => null]),
            logger: new RecordingLogger(),
        );
    }

    public function testAnInlineValueWithABlankKeyIsRejected(): void
    {
        $this->expectException(ConfigDirectorValidationException::class);

        ConfigDirector::createClient(
            connection: new ConnectionOptions(mode: ConnectionMode::Offline, values: [" \t" => true]),
            logger: new RecordingLogger(),
        );
    }

    public function testAnInlineInfiniteFloatIsRejected(): void
    {
        $this->expectException(ConfigDirectorValidationException::class);

        ConfigDirector::createClient(
            connection: new ConnectionOptions(mode: ConnectionMode::Offline, values: ['discount-rate' => -INF]),
            logger: new RecordingLogger(),
        );
    }

    public function testAnInlineFloatThatIsNotANumberIsRejected(): void
    {
        $this->expectException(ConfigDirectorValidationException::class);

        ConfigDirector::createClient(
            connection: new ConnectionOptions(mode: ConnectionMode::Offline, values: ['discount-rate' => NAN]),
            logger: new RecordingLogger(),
        );
    }

    public function testAnInlineObjectIsRejected(): void
    {
        $this->expectException(ConfigDirectorValidationException::class);

        ConfigDirector::createClient(
            connection: new ConnectionOptions(
                mode: ConnectionMode::Offline,
                // @phpstan-ignore argument.type
                values: ['launch-date' => new \DateTimeImmutable('2026-11-13')],
            ),
            logger: new RecordingLogger(),
        );
    }

    public function testAnInlineResourceIsRejected(): void
    {
        $this->expectException(ConfigDirectorValidationException::class);

        ConfigDirector::createClient(
            // @phpstan-ignore argument.type
            connection: new ConnectionOptions(mode: ConnectionMode::Offline, values: ['output' => STDOUT]),
            logger: new RecordingLogger(),
        );
    }

    public function testAnObjectInsideAnInlineArrayIsRejected(): void
    {
        $this->expectException(ConfigDirectorValidationException::class);

        ConfigDirector::createClient(
            connection: new ConnectionOptions(
                mode: ConnectionMode::Offline,
                values: ['launch' => ['dates' => [new \DateTimeImmutable('2026-11-13')]]],
            ),
            logger: new RecordingLogger(),
        );
    }

    public function testAnObjectInsideAnInlineStdClassIsRejected(): void
    {
        $this->expectException(ConfigDirectorValidationException::class);

        ConfigDirector::createClient(
            connection: new ConnectionOptions(
                mode: ConnectionMode::Offline,
                values: ['launch' => ['window' => (object) ['start' => new \DateTimeImmutable('2026-11-13')]]],
            ),
            logger: new RecordingLogger(),
        );
    }

    public function testAnInlineValueThatContainsItselfIsRejected(): void
    {
        $node = new \stdClass();
        $node->next = $node;

        $this->expectException(ConfigDirectorValidationException::class);

        ConfigDirector::createClient(
            connection: new ConnectionOptions(mode: ConnectionMode::Offline, values: ['chain' => ['first' => $node]]),
            logger: new RecordingLogger(),
        );
    }

    public function testAnInfiniteFloatInsideAnInlineArrayIsRejected(): void
    {
        $this->expectException(ConfigDirectorValidationException::class);

        ConfigDirector::createClient(
            connection: new ConnectionOptions(mode: ConnectionMode::Offline, values: ['theme' => ['ratio' => INF]]),
            logger: new RecordingLogger(),
        );
    }

    public function testATextThatIsNotUtf8InsideAnInlineArrayIsRejected(): void
    {
        $this->expectException(ConfigDirectorValidationException::class);

        ConfigDirector::createClient(
            connection: new ConnectionOptions(mode: ConnectionMode::Offline, values: ['theme' => ['name' => "caf\xE9"]]),
            logger: new RecordingLogger(),
        );
    }

    public function testAnOfflineClientWithAFlushIntervalOfZeroIsRejected(): void
    {
        $this->expectException(ConfigDirectorValidationException::class);

        ConfigDirector::createClient(
            connection: new ConnectionOptions(mode: ConnectionMode::Offline),
            logger: new RecordingLogger(),
            telemetry: new TelemetryOptions(flushInterval: 0),
        );
    }

    #[DoesNotPerformAssertions]
    public function testAnOfflineClientIgnoresATimeoutOfZero(): void
    {
        ConfigDirector::createClient(
            connection: new ConnectionOptions(mode: ConnectionMode::Offline, timeout: 0),
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
