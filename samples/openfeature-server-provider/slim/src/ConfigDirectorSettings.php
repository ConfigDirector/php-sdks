<?php

declare(strict_types=1);

namespace App;

use ConfigDirector\ConnectionMode;
use ConfigDirector\ConnectionOptions;

final readonly class ConfigDirectorSettings
{
    public function __construct(
        public string $serverSdkKey,
        public ConnectionOptions $connection,
        public ?string $logLevel,
    ) {}

    /**
     * @param array<string, string|null> $environment
     */
    public static function fromEnvironment(array $environment): self
    {
        $connectionOptionsNamed = array_filter(
            [
                'mode' => self::connectionModeIn($environment),
                'timeout' => self::timeoutIn($environment),
                'url' => self::valueIn($environment, 'CONFIGDIRECTOR_BASE_URL'),
            ],
            static fn(mixed $option): bool => $option !== null,
        );

        return new self(
            serverSdkKey: self::valueIn($environment, 'CONFIGDIRECTOR_SERVER_KEY') ?? 'fake-sample-key',
            connection: new ConnectionOptions(...$connectionOptionsNamed),
            logLevel: self::valueIn($environment, 'CONFIGDIRECTOR_LOG_LEVEL'),
        );
    }

    /**
     * @param array<string, string|null> $environment
     */
    private static function connectionModeIn(array $environment): ?ConnectionMode
    {
        $mode = self::valueIn($environment, 'CONFIGDIRECTOR_MODE');
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
     * @param array<string, string|null> $environment
     */
    private static function timeoutIn(array $environment): ?int
    {
        $timeout = self::valueIn($environment, 'CONFIGDIRECTOR_TIMEOUT');
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
     * @param array<string, string|null> $environment
     */
    private static function valueIn(array $environment, string $name): ?string
    {
        $value = $environment[$name] ?? null;

        return $value === '' ? null : $value;
    }
}
