<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Client;

use ConfigDirector\ConfigDirectorClient;

final class ClientsDisposedAtShutdown
{
    /** @var \WeakMap<ConfigDirectorClient, true>|null */
    private static ?\WeakMap $clients = null;

    private function __construct() {}

    public static function add(ConfigDirectorClient $client): void
    {
        if (self::$clients === null) {
            self::$clients = new \WeakMap();
            register_shutdown_function(static function (): void {
                register_shutdown_function(self::disposeEveryClient(...));
            });
        }

        self::$clients[$client] = true;
    }

    private static function disposeEveryClient(): void
    {
        foreach (self::$clients ?? [] as $client => $isAdded) {
            $client->dispose();
        }
    }
}
