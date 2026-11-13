<?php

declare(strict_types=1);

namespace ConfigDirector;

/**
 * Handlers for the events a {@see ConfigDirectorClient} emits, given when the client is created.
 * The client registers them as {@see ConfigDirectorClient::on()} would, before any event can fire.
 * Each event takes one handler or a list of handlers. An entry of a list that is not callable is
 * ignored and a warning is logged.
 *
 * ```
 * $client = ConfigDirector::createClient(
 *     serverSdkKey: 'YOUR-SERVER-SDK-KEY',
 *     hooks: new ClientHooks(
 *         configEvaluated: function (ConfigEvaluatedEvent $event): void {
 *             error_log("Evaluated {$event->evaluation->key}");
 *         },
 *     ),
 * );
 * ```
 */
final readonly class ClientHooks
{
    /** @var callable|array<array-key, mixed>|null The `clientReady` handlers, as given. */
    public mixed $clientReady;

    /** @var callable|array<array-key, mixed>|null The `configsUpdated` handlers, as given. */
    public mixed $configsUpdated;

    /** @var callable|array<array-key, mixed>|null The `configEvaluated` handlers, as given. */
    public mixed $configEvaluated;

    /** @var callable|array<array-key, mixed>|null The `connectionError` handlers, as given. */
    public mixed $connectionError;

    /**
     * @param (callable(): void)|list<callable(): void>|null $clientReady Called with no argument
     *     when the client first receives values and becomes ready.
     * @param (callable(ConfigsUpdatedEvent): void)|list<callable(ConfigsUpdatedEvent): void>|null $configsUpdated
     *     Called each time the client receives values, the first time included.
     * @param (callable(ConfigEvaluatedEvent): void)|list<callable(ConfigEvaluatedEvent): void>|null $configEvaluated
     *     Called each time a config is evaluated, before the read returns.
     * @param (callable(ConnectionErrorEvent): void)|list<callable(ConnectionErrorEvent): void>|null $connectionError
     *     Called with a {@see ConnectionErrorEvent}. Nothing calls it yet: the client does not
     *     connect to ConfigDirector yet.
     */
    public function __construct(
        callable|array|null $clientReady = null,
        callable|array|null $configsUpdated = null,
        callable|array|null $configEvaluated = null,
        callable|array|null $connectionError = null,
    ) {
        $this->clientReady = $clientReady;
        $this->configsUpdated = $configsUpdated;
        $this->configEvaluated = $configEvaluated;
        $this->connectionError = $connectionError;
    }
}
