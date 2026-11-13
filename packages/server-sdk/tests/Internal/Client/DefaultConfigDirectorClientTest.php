<?php

declare(strict_types=1);

namespace ConfigDirector\Tests\Internal\Client;

use ConfigDirector\ConfigDirectorConnectionException;
use ConfigDirector\ConfigEvaluation;
use ConfigDirector\ConfigsUpdatedEvent;
use ConfigDirector\ConnectionErrorEvent;
use ConfigDirector\EvaluationReason;
use ConfigDirector\Internal\Client\DefaultConfigDirectorClient;
use ConfigDirector\Internal\Client\EventHandlers;
use ConfigDirector\Internal\Connection\ConfigDefinition;
use ConfigDirector\Internal\Connection\Connection;
use ConfigDirector\Internal\Connection\ServerSdkPayload;
use ConfigDirector\Internal\Connection\UndeliveredPayload;
use ConfigDirector\Internal\Evaluation\TargetingRules;
use ConfigDirector\Metadata;
use ConfigDirector\Tests\Support\CloseCountingConnection;
use ConfigDirector\Tests\Support\RecordingLogger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

final class DefaultConfigDirectorClientTest extends TestCase
{
    public function testConfigsUpdatedNamesTheKeysALaterPayloadNoLongerCarries(): void
    {
        $updates = [];
        $client = new DefaultConfigDirectorClient(
            self::connectionDelivering(
                self::payloadWith('integer-config', 'day-of-the-week-config', 'float-config'),
                self::payloadWith('day-of-the-week-config'),
            ),
            new Metadata(),
            250,
            new RecordingLogger(),
            new EventHandlers(new RecordingLogger()),
        );
        $client->on('configsUpdated', function (ConfigsUpdatedEvent $update) use (&$updates): void {
            $updates[] = $update;
        });
        $client->initialize();

        $client->initialize();

        self::assertEquals(
            [
                new ConfigsUpdatedEvent(['integer-config', 'day-of-the-week-config', 'float-config'], []),
                new ConfigsUpdatedEvent([], ['integer-config', 'float-config']),
            ],
            $updates,
        );
    }

    public function testAWatchOnAConfigALaterPayloadNoLongerCarriesReceivesItsInCodeDefaultValue(): void
    {
        $values = [];
        $client = new DefaultConfigDirectorClient(
            self::connectionDelivering(
                self::payloadWith('integer-config', 'day-of-the-week-config'),
                self::payloadWith('day-of-the-week-config'),
            ),
            new Metadata(),
            250,
            new RecordingLogger(),
            new EventHandlers(new RecordingLogger()),
        );
        $client->watch('integer-config', 'fallback', function (string $value) use (&$values): void {
            $values[] = $value;
        });
        $client->initialize();

        $client->initialize();

        self::assertSame(['value-of-integer-config', 'fallback'], $values);
    }

    public function testADisposedClientTakesInNoPayloadAReadFinds(): void
    {
        $connection = new class (self::payloadWith('integer-config')) implements Connection {
            public function __construct(private readonly ServerSdkPayload $payload) {}

            #[\Override]
            public function payloadForInitialization(): ServerSdkPayload
            {
                return $this->payload;
            }

            #[\Override]
            public function payloadFoundByARead(): ServerSdkPayload
            {
                return $this->payload;
            }

            #[\Override]
            public function close(): void {}
        };
        $client = new DefaultConfigDirectorClient(
            $connection,
            new Metadata(),
            250,
            new RecordingLogger(),
            new EventHandlers(new RecordingLogger()),
        );
        $client->initialize();
        $client->dispose();

        $isReady = $client->isReady();

        self::assertFalse($isReady);
    }

    public function testDisposeClosesTheConnection(): void
    {
        $connection = self::connectionCountingItsCloses();
        $client = new DefaultConfigDirectorClient(
            $connection,
            new Metadata(),
            250,
            new RecordingLogger(),
            new EventHandlers(new RecordingLogger()),
        );

        $client->dispose();

        self::assertSame(1, $connection->timesClosed);
    }

    public function testDisposingTwiceClosesTheConnectionOnce(): void
    {
        $connection = self::connectionCountingItsCloses();
        $client = new DefaultConfigDirectorClient(
            $connection,
            new Metadata(),
            250,
            new RecordingLogger(),
            new EventHandlers(new RecordingLogger()),
        );
        $client->dispose();

        $client->dispose();

        self::assertSame(1, $connection->timesClosed);
    }

    public function testAClientThatIsNoLongerReferencedClosesItsConnection(): void
    {
        $connection = self::connectionCountingItsCloses();
        $client = new DefaultConfigDirectorClient(
            $connection,
            new Metadata(),
            250,
            new RecordingLogger(),
            new EventHandlers(new RecordingLogger()),
        );

        unset($client);

        self::assertSame(1, $connection->timesClosed);
    }

    public function testAConnectionErrorDeliveredWhileTheConnectionClosesReachesAHandlerOfTheClient(): void
    {
        $eventHandlers = new EventHandlers(new RecordingLogger());
        $error = new ConfigDirectorConnectionException('Connection failed with status: 401', 401);
        $client = new DefaultConfigDirectorClient(
            self::connectionCountingItsCloses(static function () use ($eventHandlers, $error): void {
                $eventHandlers->deliverConnectionError($error);
            }),
            new Metadata(),
            250,
            new RecordingLogger(),
            $eventHandlers,
        );
        $eventsReceived = [];
        $client->on('connectionError', function (ConnectionErrorEvent $event) use (&$eventsReceived): void {
            $eventsReceived[] = $event;
        });

        $client->dispose();

        self::assertEquals([new ConnectionErrorEvent($error)], $eventsReceived);
    }

    public function testAReadInsideAConnectionErrorHandlerCalledWhileDisposingReturnsTheValueServed(): void
    {
        $eventHandlers = new EventHandlers(new RecordingLogger());
        $client = new DefaultConfigDirectorClient(
            self::connectionCountingItsCloses(static function () use ($eventHandlers): void {
                $eventHandlers->deliverConnectionError(new ConfigDirectorConnectionException('refused', 401));
            }),
            new Metadata(),
            250,
            new RecordingLogger(),
            $eventHandlers,
        );
        $client->initialize();
        $valuesRead = [];
        $client->on('connectionError', function () use ($client, &$valuesRead): void {
            $valuesRead[] = $client->getValue('integer-config', 'fallback');
        });

        $client->dispose();

        self::assertSame(['value-of-integer-config'], $valuesRead);
    }

    private static function connectionDelivering(ServerSdkPayload ...$payloads): Connection
    {
        return new class (array_values($payloads)) implements Connection {
            /**
             * @param list<ServerSdkPayload> $payloads
             */
            public function __construct(private array $payloads) {}

            #[\Override]
            public function payloadForInitialization(): ServerSdkPayload|UndeliveredPayload
            {
                return array_shift($this->payloads) ?? UndeliveredPayload::ReasonLoggedAsAnError;
            }

            #[\Override]
            public function payloadFoundByARead(): null
            {
                return null;
            }

            #[\Override]
            public function close(): void {}
        };
    }

    private static function payloadWith(string ...$configKeys): ServerSdkPayload
    {
        $configs = [];
        foreach ($configKeys as $configKey) {
            $configs[$configKey] = new ConfigDefinition(
                "id-of-{$configKey}",
                $configKey,
                'string',
                new TargetingRules("value-of-{$configKey}", "value-id-of-{$configKey}", null, []),
            );
        }

        return new ServerSdkPayload($configs, []);
    }

    /**
     * @param (\Closure(): void)|null $whileClosing
     */
    private static function connectionCountingItsCloses(?\Closure $whileClosing = null): CloseCountingConnection
    {
        return new CloseCountingConnection(self::payloadWith('integer-config'), $whileClosing);
    }
}
