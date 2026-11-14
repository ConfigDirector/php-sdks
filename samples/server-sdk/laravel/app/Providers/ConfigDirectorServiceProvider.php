<?php

declare(strict_types=1);

namespace App\Providers;

use App\ConfigDirectorSettings;
use ConfigDirector\ConfigDirector;
use ConfigDirector\ConfigDirectorClient;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Log\LogManager;
use Illuminate\Support\ServiceProvider;

final class ConfigDirectorServiceProvider extends ServiceProvider
{
    #[\Override]
    public function register(): void
    {
        $this->app->singleton(
            ConfigDirectorSettings::class,
            static fn(Application $app): ConfigDirectorSettings => ConfigDirectorSettings::fromConfiguration(
                $app->make(Repository::class)->array('configdirector'),
            ),
        );

        $this->app->scoped(
            ConfigDirectorClient::class,
            static function (Application $app): ConfigDirectorClient {
                $settings = $app->make(ConfigDirectorSettings::class);
                $client = ConfigDirector::createClient(
                    serverSdkKey: $settings->serverSdkKey,
                    metadata: $settings->metadata,
                    connection: $settings->connection,
                    logger: $app->make(LogManager::class)->channel(ConfigDirectorSettings::LOG_CHANNEL),
                );
                $client->initialize();

                return $client;
            },
        );
    }
}
