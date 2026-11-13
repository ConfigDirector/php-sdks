<?php

declare(strict_types=1);

namespace ConfigDirector\Testing;

use ConfigDirector\ConfigDirectorClient;
use ConfigDirector\ConfigDirectorConnectionException;
use ConfigDirector\ConfigDirectorValidationException;
use ConfigDirector\Internal\Connection\InMemoryConnection;

/**
 * The SDK's real client over an in-memory connection that the test controls. Create one with
 * {@see ConfigDirectorTesting::createTestClient()}.
 *
 * The client under {@see $client} is the SDK's production client: value parsing, targeting
 * evaluation, watches, events and readiness behave as they do against ConfigDirector. Only the
 * connection is replaced, so no request is made, no local store is created and nothing is sent.
 *
 * While the client is connected, every change this test client makes reaches it before the call
 * returns: the `configsUpdated` handlers and the watches run inside the call, so an assertion can
 * follow it directly. As with values from ConfigDirector, they name only the configs whose value
 * changed. A change made from inside a handler or a watch reaches the client after the change
 * being handled. The client is connected from an `initialize()` that serves the values, or a
 * {@see completeInitialization()}, until its `dispose()` or an `initialize()` that is held or
 * fails. A change made while it is not connected reaches it once it is connected again.
 */
final class TestClient
{
    /**
     * @internal Use {@see ConfigDirectorTesting::createTestClient()}.
     *
     * @param ConfigDirectorClient $client The SDK client to hand to the code under test. It starts
     *     not initialized, like a production client: `initialize()` serves the stored values at
     *     once, unless initialization is held or set to fail. After its `dispose()`, the methods of
     *     this test client store values but deliver nothing.
     */
    public function __construct(
        public readonly ConfigDirectorClient $client,
        private readonly InMemoryConnection $connection,
    ) {}

    /**
     * Stores a value under a config key and, while the client is connected, delivers it, so reads,
     * watches and `configsUpdated` handlers see it. A value equal to the one the key has fires
     * nothing.
     *
     * The config type follows from the value: a `bool` is a boolean config, an `int` an integer
     * config, a `float` a float config, served as plain decimal text, a `string` a string config and
     * an array a JSON config, whether a list or a map. An array can hold `null`, booleans, numbers,
     * strings, arrays and `stdClass` objects. Every context receives the same value, as a match
     * (`found-match`) without a `valueId` or a `variation`.
     *
     * @param string $key The config key.
     * @param bool|int|float|string|array<array-key, mixed> $value The value to serve.
     *
     * @throws ConfigDirectorValidationException When the key is blank or the value is one the SDK
     *     cannot serve, such as `null`, a float that is not finite or an object other than
     *     `stdClass`. Nothing changes then.
     */
    public function setValue(string $key, mixed $value): void
    {
        $this->connection->setValue($key, $value);
    }

    /**
     * Removes the value of a config key and, while the client is connected, delivers the change:
     * reads return their in-code default value with `config-state-missing`, the watches on the key
     * receive that in-code default value, and `configsUpdated` handlers find the key in
     * `removedKeys`.
     *
     * @param string $key The config key.
     */
    public function removeValue(string $key): void
    {
        $this->connection->removeValue($key);
    }

    /**
     * Replaces every stored value, cancels a {@see holdInitialization()} or
     * {@see failInitialization()} that no `initialize()` has used yet, and, while the client is
     * connected, delivers the change. Use it to reset a test client shared across tests.
     *
     * @param array<string, bool|int|float|string|array<array-key, mixed>> $values The values to
     *     serve from now on, keyed by config key, as {@see setValue()} takes them.
     *
     * @throws ConfigDirectorValidationException As {@see setValue()}. Nothing changes then.
     */
    public function replaceValues(array $values): void
    {
        $this->connection->replaceValues($values);
    }

    /**
     * Makes the next `initialize()` wait for the client's timeout, log the warning a production
     * client logs when its wait reaches the timeout, and return without values: a client that was
     * not ready stays not ready, so reads return their in-code default value with
     * `client-not-ready`, and one that was ready keeps serving the values it had. PHP runs a script
     * on one thread, so nothing settles that initialization while `initialize()` waits:
     * {@see completeInitialization()} or {@see failInitialization()} settles it after it returns.
     */
    public function holdInitialization(): void
    {
        $this->connection->holdInitialization();
    }

    /**
     * Delivers the stored values to an initialization that {@see holdInitialization()} held: the
     * `configsUpdated` handlers receive every config key, then the watches run, then the
     * `clientReady` handlers, and the client is ready when this returns. Called before
     * `initialize()`, it cancels the hold instead. Otherwise it does nothing.
     */
    public function completeInitialization(): void
    {
        $this->connection->completeInitialization();
    }

    /**
     * Makes the next `initialize()` fail as a refused server SDK key does: it returns at once
     * without values, logs an error, and the `connectionError` handlers receive a
     * {@see ConfigDirectorConnectionException} with the status 401. A client that was not ready
     * stays not ready, and one that was ready keeps serving the values it had. Called after a held
     * `initialize()` returned, it fails that initialization the same way at once, and a later
     * {@see completeInitialization()} does nothing. The `initialize()` after a failed one serves
     * the stored values.
     */
    public function failInitialization(): void
    {
        $this->connection->failInitialization();
    }
}
