<?php

declare(strict_types=1);

namespace ConfigDirector\Tests\Internal\Client;

use ConfigDirector\ConfigEvaluation;
use ConfigDirector\EvaluationReason;
use ConfigDirector\Internal\Client\DefaultConfigDirectorClient;
use ConfigDirector\Internal\Connection\Connection;
use ConfigDirector\Internal\Connection\ServerSdkPayload;
use ConfigDirector\Metadata;
use ConfigDirector\Tests\Support\RecordingLogger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

final class DefaultConfigDirectorClientTest extends TestCase
{
    public function testAClientWhoseConnectionDeliversNothingInTimeIsNotReady(): void
    {
        $client = new DefaultConfigDirectorClient(
            self::connectionDeliveringNothing(),
            new Metadata(),
            250,
            new RecordingLogger(),
        );

        $client->initialize();

        self::assertFalse($client->isReady());
    }

    public function testATimedOutInitializeLogsOneWarningWithTheTimeout(): void
    {
        $logger = new RecordingLogger();
        $client = new DefaultConfigDirectorClient(self::connectionDeliveringNothing(), new Metadata(), 250, $logger);

        $client->initialize();

        self::assertSame([['timeout' => 250]], $logger->contextsLoggedAt(LogLevel::WARNING));
    }

    public function testAReadAfterATimedOutInitializeReturnsTheInCodeDefaultValue(): void
    {
        $client = new DefaultConfigDirectorClient(
            self::connectionDeliveringNothing(),
            new Metadata(),
            250,
            new RecordingLogger(),
        );
        $client->initialize();

        $evaluation = $client->evaluate('integer-config', 10);

        self::assertEquals(
            new ConfigEvaluation('integer-config', 10, null, null, true, EvaluationReason::ClientNotReady, null),
            $evaluation,
        );
    }

    public function testInitializeWaitsForTheConnectionUpToTheConfiguredTimeout(): void
    {
        $connection = new class implements Connection {
            public ?int $timeoutWaited = null;

            #[\Override]
            public function payloadDeliveredWithin(int $timeoutMilliseconds): ?ServerSdkPayload
            {
                $this->timeoutWaited = $timeoutMilliseconds;

                return null;
            }
        };
        $client = new DefaultConfigDirectorClient($connection, new Metadata(), 250, new RecordingLogger());

        $client->initialize();

        self::assertSame(250, $connection->timeoutWaited);
    }

    private static function connectionDeliveringNothing(): Connection
    {
        return new class implements Connection {
            #[\Override]
            public function payloadDeliveredWithin(int $timeoutMilliseconds): ?ServerSdkPayload
            {
                return null;
            }
        };
    }
}
