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
     * @param int $timeout The time `initialize()` waits for values, in milliseconds, which is also
     *     the most a request to ConfigDirector may take. Defaults to 3,000 milliseconds (3
     *     seconds). This option has no effect in offline mode.
     * @param string $url The base URL of the ConfigDirector SDK server. To be used only when
     *     routing through a proxy to connect to it. This option has no effect in offline mode.
     * @param string|null $file The path of a JSON file holding the values to serve in offline mode,
     *     with one value per config key, such as `{"new-checkout": true, "max-items": 20}`. Each
     *     value's config type follows from the value, as for `$values`, and a JSON object stays a
     *     JSON object. A relative path is resolved against the working directory, which under
     *     PHP-FPM is the folder of the script it runs, so prefer `__DIR__ . '/flags.json'`.
     *     `initialize()` reads it and throws {@see ConfigDirectorInitializationException} when the
     *     path is a URL or another stream such as `php://`, since an offline client never connects,
     *     or when the file is missing, cannot be read, does not hold a JSON object or holds a value
     *     the SDK cannot serve, such as `null`. Only one of `$file` and `$values` can be given, and
     *     only in offline mode.
     * @param array<string, bool|int|float|string|array<array-key, mixed>>|null $values The values to
     *     serve in offline mode, keyed by config key. The config type follows from the value: a
     *     `bool` is a boolean config, an `int` an integer config, a `float` a float config, a
     *     `string` a string config and an array a JSON config, whether a list or a map. An array
     *     can hold `null`, booleans, numbers, strings, arrays and `stdClass` objects. Every context
     *     receives the same value. Only one of `$file` and `$values` can be given, and only in
     *     offline mode.
     * @param string|null $storagePath The folder in which the SDK keeps the values it received, in
     *     a folder of its own for each server SDK key, as an absolute path. Defaults to a folder
     *     in the system temp directory that belongs to the system user running PHP. On Linux and
     *     macOS, only that user can read what the SDK writes there. This option has no effect in
     *     offline mode.
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
