<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\ConfigDirectorSettings;
use ConfigDirector\ConnectionMode;
use ConfigDirector\ConnectionOptions;
use ConfigDirector\Metadata;
use PHPUnit\Framework\TestCase;

final class ConfigDirectorSettingsTest extends TestCase
{
    public function testAnEmptyConfigurationGivesTheSampleKeyAndTheSdkDefaults(): void
    {
        $settings = ConfigDirectorSettings::fromConfiguration([]);

        self::assertEquals(
            new ConfigDirectorSettings(
                'fake-sample-key',
                new Metadata(appName: 'laravel-sample', appVersion: '1.0.0'),
                new ConnectionOptions(),
            ),
            $settings,
        );
    }

    public function testAnEmptyValueCountsAsUnset(): void
    {
        $settings = ConfigDirectorSettings::fromConfiguration([
            'server_key' => '',
            'base_url' => '',
            'mode' => '',
            'timeout' => '',
            'storage_path' => '',
        ]);

        self::assertEquals(
            new ConfigDirectorSettings(
                'fake-sample-key',
                new Metadata(appName: 'laravel-sample', appVersion: '1.0.0'),
                new ConnectionOptions(),
            ),
            $settings,
        );
    }

    public function testTheSettingsReadTheConfiguration(): void
    {
        $settings = ConfigDirectorSettings::fromConfiguration([
            'server_key' => 'sk-live',
            'base_url' => 'https://proxy.internal/configdirector',
            'mode' => 'offline',
            'timeout' => '1500',
            'storage_path' => '/var/lib/configdirector',
        ]);

        self::assertEquals(
            new ConfigDirectorSettings(
                'sk-live',
                new Metadata(appName: 'laravel-sample', appVersion: '1.0.0'),
                new ConnectionOptions(
                    mode: ConnectionMode::Offline,
                    timeout: 1500,
                    url: 'https://proxy.internal/configdirector',
                    storagePath: '/var/lib/configdirector',
                ),
            ),
            $settings,
        );
    }

    public function testATimeoutWrittenAsANumberInTheConfigurationIsRead(): void
    {
        $settings = ConfigDirectorSettings::fromConfiguration(['timeout' => 1500]);

        self::assertEquals(new ConnectionOptions(timeout: 1500), $settings->connection);
    }

    public function testATimeoutThatIsNotAWholeNumberOfMillisecondsIsRejected(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('CONFIGDIRECTOR_TIMEOUT');

        ConfigDirectorSettings::fromConfiguration(['timeout' => '1.5s']);
    }

    public function testAModeThatIsNotAConnectionModeIsRejected(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('CONFIGDIRECTOR_MODE');

        ConfigDirectorSettings::fromConfiguration(['mode' => 'streaming']);
    }
}
