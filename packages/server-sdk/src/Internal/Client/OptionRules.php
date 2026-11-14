<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Client;

use ConfigDirector\ConfigDirectorValidationException;
use ConfigDirector\ConnectionOptions;
use ConfigDirector\Internal\Logging\LineLogger;
use ConfigDirector\TelemetryOptions;
use Psr\Log\LoggerInterface;

final class OptionRules
{
    private const int MINIMUM_EVENT_QUEUE_LIMIT = 100;

    private const int MAXIMUM_EVENT_QUEUE_LIMIT = 100_000;

    private function __construct() {}

    public static function errorLogLoggerWritingFrom(string $logLevel): LoggerInterface
    {
        return LineLogger::writingThroughErrorLogFrom($logLevel) ?? self::rejectLogLevel($logLevel);
    }

    public static function standardErrorLoggerWritingFrom(string $logLevel): LoggerInterface
    {
        return LineLogger::writingToStandardErrorFrom($logLevel) ?? self::rejectLogLevel($logLevel);
    }

    public static function nonBlankServerSdkKey(?string $serverSdkKey, string $whatCannotBeCreatedWithoutIt): string
    {
        if ($serverSdkKey === null || trim($serverSdkKey) === '') {
            throw new ConfigDirectorValidationException(
                "No server SDK key was provided, {$whatCannotBeCreatedWithoutIt} without a valid server SDK key",
            );
        }

        return $serverSdkKey;
    }

    public static function rejectUnusableTimeout(int $timeoutMilliseconds): void
    {
        if ($timeoutMilliseconds <= 0) {
            throw new ConfigDirectorValidationException(
                "Invalid timeout '{$timeoutMilliseconds}'. The timeout must be a positive number of milliseconds.",
            );
        }
    }

    public static function rejectUnusableConnectionToConfigDirector(ConnectionOptions $connectionOptions): void
    {
        if ($connectionOptions->file !== null || $connectionOptions->values !== null) {
            throw new ConfigDirectorValidationException(
                "The connection options 'file' and 'values' apply only when the connection mode is 'offline', but "
                . "the mode is '{$connectionOptions->mode->value}'.",
            );
        }
        self::rejectUnusableBaseUrl($connectionOptions->url);
        self::rejectUnusableStoragePath($connectionOptions->storagePath);
        self::rejectUnusableTimeout($connectionOptions->timeout);
    }

    private static function rejectLogLevel(string $logLevel): never
    {
        throw new ConfigDirectorValidationException(
            "Invalid log level '{$logLevel}'. The log level must be one of the levels of Psr\\Log\\LogLevel, "
            . "such as 'debug' or 'warning'.",
        );
    }

    public static function rejectUnusableTelemetry(TelemetryOptions $telemetry): void
    {
        $eventQueueLimitIsInRange = $telemetry->eventQueueLimit >= self::MINIMUM_EVENT_QUEUE_LIMIT
            && $telemetry->eventQueueLimit <= self::MAXIMUM_EVENT_QUEUE_LIMIT;
        if (!$eventQueueLimitIsInRange) {
            throw new ConfigDirectorValidationException(sprintf(
                "Invalid telemetry event queue limit '%d'. It must be between %d and %d.",
                $telemetry->eventQueueLimit,
                self::MINIMUM_EVENT_QUEUE_LIMIT,
                self::MAXIMUM_EVENT_QUEUE_LIMIT,
            ));
        }
        if ($telemetry->flushInterval <= 0) {
            throw new ConfigDirectorValidationException(
                "Invalid telemetry flush interval '{$telemetry->flushInterval}'. "
                . 'It must be a positive number of milliseconds.',
            );
        }
    }

    private static function rejectUnusableBaseUrl(string $baseUrl): void
    {
        $urlParts = parse_url($baseUrl);
        $scheme = strtolower($urlParts['scheme'] ?? '');
        $isHttpUrl = in_array($scheme, ['http', 'https'], true) && ($urlParts['host'] ?? '') !== '';
        $holdsSpacesOrControlCharacters = preg_match('/[\x00-\x20\x7F]/', $baseUrl) === 1;
        if (!$isHttpUrl || $holdsSpacesOrControlCharacters) {
            throw new ConfigDirectorValidationException(
                "Invalid base URL '{$baseUrl}'. The base URL must be an http or https URL.",
            );
        }
    }

    private static function rejectUnusableStoragePath(?string $storagePath): void
    {
        if ($storagePath === null) {
            return;
        }

        $isAbsolute = PHP_OS_FAMILY === 'Windows'
            ? preg_match('/^(?:[A-Za-z]:[\\\\\/]|\\\\\\\\)/', $storagePath) === 1
            : str_starts_with($storagePath, '/');
        if (!$isAbsolute) {
            throw new ConfigDirectorValidationException(
                "Invalid storage path '{$storagePath}'. The storage path must be an absolute path.",
            );
        }
    }
}
