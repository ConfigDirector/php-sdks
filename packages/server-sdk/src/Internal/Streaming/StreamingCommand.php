<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Streaming;

use ConfigDirector\ConfigDirector;
use ConfigDirector\ConfigDirectorException;
use ConfigDirector\ConfigDirectorValidationException;
use ConfigDirector\ConnectionOptions;
use ConfigDirector\Internal\Client\OptionRules;
use ConfigDirector\Metadata;
use Psr\Log\LogLevel;

final class StreamingCommand
{
    private function __construct() {}

    /**
     * @param array<string, string> $environment
     */
    public static function exitCodeOfARunWith(array $environment): int
    {
        $serverSdkKey = self::settingNamed('CONFIGDIRECTOR_SERVER_KEY', $environment);
        if ($serverSdkKey === null) {
            fwrite(
                STDERR,
                "CONFIGDIRECTOR_SERVER_KEY is not set. Set it to the server SDK key the application uses.\n",
            );

            return 1;
        }

        try {
            $logger = OptionRules::standardErrorLoggerWritingFrom(
                self::settingNamed('CONFIGDIRECTOR_LOG_LEVEL', $environment) ?? LogLevel::INFO,
            );
            $streamingProcess = ConfigDirector::createStreamingProcess(
                serverSdkKey: $serverSdkKey,
                metadata: new Metadata(
                    appName: self::settingNamed('CONFIGDIRECTOR_APP_NAME', $environment),
                    appVersion: self::settingNamed('CONFIGDIRECTOR_APP_VERSION', $environment),
                ),
                connection: new ConnectionOptions(
                    url: self::settingNamed('CONFIGDIRECTOR_BASE_URL', $environment) ?? (new ConnectionOptions())->url,
                    storagePath: self::settingNamed('CONFIGDIRECTOR_STORAGE_PATH', $environment),
                ),
                logger: $logger,
            );
        } catch (ConfigDirectorValidationException $rejectedSetting) {
            fwrite(STDERR, $rejectedSetting->getMessage() . "\n");

            return 1;
        }

        try {
            $streamingProcess->run();
        } catch (ConfigDirectorException) {
            return 1;
        } catch (\Throwable $unexpectedFailure) {
            $logger->error(
                '[StreamingProcess] The streaming process stops after a failure it does not expect.',
                ['exception' => $unexpectedFailure],
            );

            return 1;
        }

        return 0;
    }

    /**
     * @param array<string, string> $environment
     */
    private static function settingNamed(string $variable, array $environment): ?string
    {
        $value = trim($environment[$variable] ?? '');

        return $value === '' ? null : $value;
    }
}
