<?php

declare(strict_types=1);

namespace App;

use ConfigDirector\ConnectionMode;
use ConfigDirector\ConnectionOptions;
use ConfigDirector\Metadata;

final readonly class ConfigDirectorSettings
{
    public const string LOG_CHANNEL = 'configdirector';

    public function __construct(
        public string $serverSdkKey,
        public Metadata $metadata,
        public ConnectionOptions $connection,
    ) {}

    /**
     * @param array<array-key, mixed> $configuration
     */
    public static function fromConfiguration(array $configuration): self
    {
        $connectionOptionsNamed = array_filter(
            [
                'mode' => self::connectionModeIn($configuration),
                'timeout' => self::timeoutIn($configuration),
                'url' => self::valueIn($configuration, 'base_url'),
                'storagePath' => self::valueIn($configuration, 'storage_path'),
            ],
            static fn(mixed $option): bool => $option !== null,
        );

        return new self(
            serverSdkKey: self::valueIn($configuration, 'server_key') ?? 'fake-sample-key',
            metadata: new Metadata(appName: 'laravel-sample', appVersion: '1.0.0'),
            connection: new ConnectionOptions(...$connectionOptionsNamed),
        );
    }

    /**
     * @param array<array-key, mixed> $configuration
     */
    private static function connectionModeIn(array $configuration): ?ConnectionMode
    {
        $mode = self::valueIn($configuration, 'mode');
        if ($mode === null) {
            return null;
        }

        $connectionMode = ConnectionMode::tryFrom($mode);
        if ($connectionMode === null) {
            $modes = implode(
                ' or ',
                array_map(static fn(ConnectionMode $mode): string => $mode->value, ConnectionMode::cases()),
            );
            throw new \UnexpectedValueException("CONFIGDIRECTOR_MODE '{$mode}' is not {$modes}.");
        }

        return $connectionMode;
    }

    /**
     * @param array<array-key, mixed> $configuration
     */
    private static function timeoutIn(array $configuration): ?int
    {
        $timeout = self::valueIn($configuration, 'timeout');
        if ($timeout === null) {
            return null;
        }

        $milliseconds = filter_var($timeout, FILTER_VALIDATE_INT);
        if ($milliseconds === false) {
            throw new \UnexpectedValueException(
                "CONFIGDIRECTOR_TIMEOUT '{$timeout}' is not a whole number of milliseconds, such as 3000.",
            );
        }

        return $milliseconds;
    }

    /**
     * @param array<array-key, mixed> $configuration
     */
    private static function valueIn(array $configuration, string $key): ?string
    {
        $value = $configuration[$key] ?? null;
        if (is_int($value)) {
            return (string) $value;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }
}
