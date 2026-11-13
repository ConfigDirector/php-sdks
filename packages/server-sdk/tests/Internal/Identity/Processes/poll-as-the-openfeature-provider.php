<?php

declare(strict_types=1);

namespace ConfigDirector\OpenFeature {
    final class Version
    {
        public const string CURRENT = '9.8.7';
    }
}

namespace {
    use ConfigDirector\ConnectionOptions;
    use ConfigDirector\Internal\Identity\Wrapper;
    use Psr\Log\NullLogger;

    if (!isset($argv)) {
        throw new RuntimeException('This script takes its arguments from the command line.');
    }
    [, $autoloadFile, $serverUrl, $storagePath] = $argv;

    require $autoloadFile;

    Wrapper::OpenFeatureServerProvider->createClient(
        serverSdkKey: 'server-sdk-key',
        connection: new ConnectionOptions(url: $serverUrl, storagePath: $storagePath),
        logger: new NullLogger(),
    )->initialize();
}
