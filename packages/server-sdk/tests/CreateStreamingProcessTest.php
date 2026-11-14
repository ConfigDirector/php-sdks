<?php

declare(strict_types=1);

namespace ConfigDirector\Tests;

use ConfigDirector\ConfigDirector;
use ConfigDirector\ConfigDirectorValidationException;
use ConfigDirector\ConnectionMode;
use ConfigDirector\ConnectionOptions;
use ConfigDirector\TelemetryOptions;
use ConfigDirector\Tests\Support\RecordingLogger;
use ConfigDirector\Tests\Support\TemporaryFolder;
use PHPUnit\Framework\TestCase;

final class CreateStreamingProcessTest extends TestCase
{
    public function testAStreamingProcessWithoutAServerSdkKeyIsRejected(): void
    {
        $this->expectException(ConfigDirectorValidationException::class);

        ConfigDirector::createStreamingProcess(logger: new RecordingLogger());
    }

    public function testAStreamingProcessWithABlankServerSdkKeyIsRejected(): void
    {
        $this->expectException(ConfigDirectorValidationException::class);

        ConfigDirector::createStreamingProcess(serverSdkKey: "  \t", logger: new RecordingLogger());
    }

    public function testAStreamingProcessInOfflineModeIsRejected(): void
    {
        $this->expectException(ConfigDirectorValidationException::class);

        ConfigDirector::createStreamingProcess(
            serverSdkKey: 'server-sdk-key',
            connection: new ConnectionOptions(mode: ConnectionMode::Offline),
            logger: new RecordingLogger(),
        );
    }

    public function testAStreamingProcessWithOfflineValuesIsRejected(): void
    {
        $this->expectException(ConfigDirectorValidationException::class);

        ConfigDirector::createStreamingProcess(
            serverSdkKey: 'server-sdk-key',
            connection: new ConnectionOptions(values: ['new-checkout' => true]),
            logger: new RecordingLogger(),
        );
    }

    public function testAStreamingProcessWithAnOfflineFileIsRejected(): void
    {
        $this->expectException(ConfigDirectorValidationException::class);

        ConfigDirector::createStreamingProcess(
            serverSdkKey: 'server-sdk-key',
            connection: new ConnectionOptions(file: __DIR__ . '/flags.json'),
            logger: new RecordingLogger(),
        );
    }

    public function testAUrlThatIsNotHttpOrHttpsIsRejected(): void
    {
        $this->expectException(ConfigDirectorValidationException::class);

        ConfigDirector::createStreamingProcess(
            serverSdkKey: 'server-sdk-key',
            connection: new ConnectionOptions(url: 'ftp://sdk-proxy.example.com'),
            logger: new RecordingLogger(),
        );
    }

    public function testARelativeStoragePathIsRejected(): void
    {
        $this->expectException(ConfigDirectorValidationException::class);

        ConfigDirector::createStreamingProcess(
            serverSdkKey: 'server-sdk-key',
            connection: new ConnectionOptions(storagePath: 'var/configdirector'),
            logger: new RecordingLogger(),
        );
    }

    public function testATimeoutOfZeroIsRejected(): void
    {
        $this->expectException(ConfigDirectorValidationException::class);

        ConfigDirector::createStreamingProcess(
            serverSdkKey: 'server-sdk-key',
            connection: new ConnectionOptions(timeout: 0),
            logger: new RecordingLogger(),
        );
    }

    public function testAnEventQueueLimitOfNinetyNineIsRejected(): void
    {
        $this->expectException(ConfigDirectorValidationException::class);

        ConfigDirector::createStreamingProcess(
            serverSdkKey: 'server-sdk-key',
            logger: new RecordingLogger(),
            telemetry: new TelemetryOptions(eventQueueLimit: 99),
        );
    }

    public function testAFlushIntervalOfZeroIsRejected(): void
    {
        $this->expectException(ConfigDirectorValidationException::class);

        ConfigDirector::createStreamingProcess(
            serverSdkKey: 'server-sdk-key',
            logger: new RecordingLogger(),
            telemetry: new TelemetryOptions(flushInterval: 0),
        );
    }

    public function testALogLevelThatIsNotAPsr3LevelIsRejected(): void
    {
        $this->expectException(ConfigDirectorValidationException::class);

        ConfigDirector::createStreamingProcess(serverSdkKey: 'server-sdk-key', logLevel: 'verbose');
    }

    public function testCreatingAStreamingProcessTouchesNoFileAndLogsNothing(): void
    {
        $storagePath = TemporaryFolder::create();
        $logger = new RecordingLogger();

        try {
            ConfigDirector::createStreamingProcess(
                serverSdkKey: 'server-sdk-key',
                connection: new ConnectionOptions(pollingInterval: 30, storagePath: $storagePath),
                logger: $logger,
            );

            self::assertSame([], TemporaryFolder::entriesOf($storagePath));
            self::assertSame([], $logger->contextsLoggedAt('warning'));
            self::assertSame([], $logger->contextsLoggedAt('info'));
        } finally {
            TemporaryFolder::remove($storagePath);
        }
    }
}
