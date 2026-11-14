<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\ConfigDirectorSettings;
use ConfigDirector\ConfigDirector;
use ConfigDirector\ConfigDirectorException;
use Illuminate\Console\Command;
use Illuminate\Log\LogManager;

final class ConfigDirectorStreamCommand extends Command
{
    protected $signature = 'configdirector:stream';

    protected $description = 'Keep the local store of this host up to date through a streaming connection';

    public function handle(ConfigDirectorSettings $settings, LogManager $logManager): int
    {
        $streamingProcess = ConfigDirector::createStreamingProcess(
            serverSdkKey: $settings->serverSdkKey,
            metadata: $settings->metadata,
            connection: $settings->connection,
            logger: $logManager->channel(ConfigDirectorSettings::LOG_CHANNEL),
        );

        try {
            $streamingProcess->run();
        } catch (ConfigDirectorException) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
