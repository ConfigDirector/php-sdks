<?php

declare(strict_types=1);

namespace ConfigDirector;

/**
 * Connection options of a {@see ConfigDirectorClient}.
 */
final readonly class ConnectionOptions
{
    /**
     * @param ConnectionMode $mode The connection mode. Defaults to {@see ConnectionMode::Polling}.
     * @param int $pollingInterval The polling interval in seconds. Defaults to 300 seconds (5
     *     minutes). The minimum is 60 seconds. A value below the minimum is raised to the minimum
     *     and a warning is logged. This option has no effect in offline mode.
     * @param int $timeout The time `initialize()` waits for values, in milliseconds. Defaults to
     *     3,000 milliseconds (3 seconds).
     * @param string $url The base URL of the ConfigDirector SDK server. To be used only when
     *     routing through a proxy to connect to it. This option has no effect in offline mode.
     * @param string|null $file The path of a JSON file holding the values to serve in offline mode.
     * @param array<string, mixed>|null $values The values to serve in offline mode, keyed by config
     *     key.
     * @param string|null $storagePath The folder where the SDK keeps the values it received, to
     *     use in place of a folder in the system temp directory.
     */
    public function __construct(
        public ConnectionMode $mode = ConnectionMode::Polling,
        public int $pollingInterval = 300,
        public int $timeout = 3_000,
        public string $url = 'https://server-sdk-api.configdirector.com',
        public ?string $file = null,
        public ?array $values = null,
        public ?string $storagePath = null,
    ) {}
}
