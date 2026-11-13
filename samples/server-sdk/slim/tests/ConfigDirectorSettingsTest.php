<?php

declare(strict_types=1);

namespace App\Tests;

use App\ConfigDirectorSettings;
use ConfigDirector\ConnectionMode;
use ConfigDirector\ConnectionOptions;
use PHPUnit\Framework\TestCase;

final class ConfigDirectorSettingsTest extends TestCase
{
    public function testAnEmptyEnvironmentGivesTheSampleKeyAndTheSdkDefaults(): void
    {
        $settings = ConfigDirectorSettings::fromEnvironment([]);

        self::assertEquals(new ConfigDirectorSettings('fake-sample-key', new ConnectionOptions(), null), $settings);
    }

    public function testAnEmptyVariableCountsAsUnset(): void
    {
        $settings = ConfigDirectorSettings::fromEnvironment([
            'CONFIGDIRECTOR_SERVER_KEY' => '',
            'CONFIGDIRECTOR_MODE' => '',
            'CONFIGDIRECTOR_TIMEOUT' => '',
            'CONFIGDIRECTOR_BASE_URL' => '',
            'CONFIGDIRECTOR_LOG_LEVEL' => '',
        ]);

        self::assertEquals(new ConfigDirectorSettings('fake-sample-key', new ConnectionOptions(), null), $settings);
    }

    public function testTheSettingsReadTheEnvironment(): void
    {
        $settings = ConfigDirectorSettings::fromEnvironment([
            'CONFIGDIRECTOR_SERVER_KEY' => 'sk-live',
            'CONFIGDIRECTOR_MODE' => 'offline',
            'CONFIGDIRECTOR_TIMEOUT' => '1500',
            'CONFIGDIRECTOR_BASE_URL' => 'https://proxy.internal/configdirector',
            'CONFIGDIRECTOR_LOG_LEVEL' => 'debug',
        ]);

        self::assertEquals(
            new ConfigDirectorSettings(
                'sk-live',
                new ConnectionOptions(
                    mode: ConnectionMode::Offline,
                    timeout: 1500,
                    url: 'https://proxy.internal/configdirector',
                ),
                'debug',
            ),
            $settings,
        );
    }

    public function testATimeoutThatIsNotAWholeNumberOfMillisecondsIsRejected(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('CONFIGDIRECTOR_TIMEOUT');

        ConfigDirectorSettings::fromEnvironment(['CONFIGDIRECTOR_TIMEOUT' => '1.5s']);
    }

    public function testAModeThatIsNotAConnectionModeIsRejected(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('CONFIGDIRECTOR_MODE');

        ConfigDirectorSettings::fromEnvironment(['CONFIGDIRECTOR_MODE' => 'streaming']);
    }
}
