<?php

declare(strict_types=1);

use ConfigDirector\Internal\Client\DefaultConfigDirectorClient;
use ConfigDirector\Internal\Client\EventHandlers;
use ConfigDirector\Internal\Connection\Connection;
use ConfigDirector\Internal\Connection\ServerSdkPayload;
use ConfigDirector\Internal\Telemetry\DiscardedTelemetry;
use ConfigDirector\Metadata;
use Psr\Log\NullLogger;

if (!isset($argv)) {
    throw new RuntimeException('This script takes its arguments from the command line.');
}
[, $autoloadFile, $wayTheScriptEnds] = $argv;

require $autoloadFile;

$connection = new class implements Connection {
    #[Override]
    public function payloadForInitialization(): ServerSdkPayload
    {
        return new ServerSdkPayload([], []);
    }

    #[Override]
    public function payloadFoundByARead(): null
    {
        return null;
    }

    #[Override]
    public function close(): void
    {
        echo "connection-closed\n";
    }
};
$client = new DefaultConfigDirectorClient(
    $connection,
    new Metadata(),
    250,
    new NullLogger(),
    new EventHandlers(new NullLogger()),
    new DiscardedTelemetry(),
);
$client->initialize();
register_shutdown_function(static function () use ($client): void {
    echo $client->isReady() ? "ready-in-the-shutdown-function-of-the-application\n" : "disposed-before-it\n";
});

echo "output-of-the-application\n";
if ($wayTheScriptEnds === 'uncaught-exception') {
    throw new RuntimeException('The application failed.');
}
if ($wayTheScriptEnds === 'fatal-error') {
    eval('final class RuntimeException {}');
}
