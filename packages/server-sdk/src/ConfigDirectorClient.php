<?php

declare(strict_types=1);

namespace ConfigDirector;

/**
 * The ConfigDirector SDK client.
 *
 * Create one with {@see ConfigDirector::createClient()} and call {@see initialize()} before the
 * first read.
 */
interface ConfigDirectorClient
{
    /**
     * Gets the values ConfigDirector serves and makes the client ready. It waits up to the
     * connection timeout. When no values arrive in time it logs a warning and returns, and every
     * read returns its in-code default value until values arrive. On a disposed client it logs a
     * warning and the client stays not ready.
     */
    public function initialize(): void;

    /**
     * Whether the client has values to serve after a call to {@see initialize()}.
     */
    public function isReady(): bool;

    /**
     * Evaluates a config and returns its value for the given context.
     *
     * @param string $configKey The config key to evaluate.
     * @param mixed $defaultValue The in-code default value, returned when no value from
     *     ConfigDirector can be served: for example before the client is ready, when no config has
     *     the key, or when the config's value cannot be read as the type of this value. Its type
     *     decides the type of the value returned: a `bool`, a `string`, an `int`, a `float`, or an
     *     array for a JSON config.
     * @param Context|null $context The context to evaluate the targeting rules for.
     *
     * @return (
     *     $defaultValue is bool ? bool : (
     *     $defaultValue is string ? string : (
     *     $defaultValue is int ? int : (
     *     $defaultValue is float ? float : array<array-key, mixed>)))
     * ) The config's value, or the in-code default value.
     *
     * @throws ConfigDirectorValidationException When the in-code default value is `null` or is not
     *     a `bool`, a `string`, an `int`, a `float` or an array.
     */
    public function getValue(
        string $configKey,
        mixed $defaultValue,
        ?Context $context = null,
    ): bool|string|int|float|array;

    /**
     * Evaluates a config as {@see getValue()} does and returns the evaluation: the value, the
     * reason it was served, its value ID and its variation.
     *
     * @param string $configKey The config key to evaluate.
     * @param mixed $defaultValue The in-code default value, returned when no value from
     *     ConfigDirector can be served. Its type decides the type of the value returned.
     * @param Context|null $context The context to evaluate the targeting rules for.
     *
     * @return ConfigEvaluation<(
     *     $defaultValue is bool ? bool : (
     *     $defaultValue is string ? string : (
     *     $defaultValue is int ? int : (
     *     $defaultValue is float ? float : array<array-key, mixed>)))
     * )> The evaluation, whose `value` is what {@see getValue()} returns.
     *
     * @throws ConfigDirectorValidationException When the in-code default value is `null` or is not
     *     a `bool`, a `string`, an `int`, a `float` or an array.
     */
    public function evaluate(string $configKey, mixed $defaultValue, ?Context $context = null): ConfigEvaluation;

    /**
     * Returns what every config serves to the given context, keyed by config key, or only the
     * configs listed in `$configKeys`. It records no telemetry, and returns an empty array when the
     * client is not ready. PHP turns an array key made only of digits into an integer, so a config
     * key such as `2024` is the integer key `2024` of the array returned.
     *
     * @param Context|null $context The context to evaluate the targeting rules for.
     * @param list<string>|null $configKeys The config keys to return. Every config is returned
     *     when left out.
     *
     * @return array<array-key, ConfigState>
     */
    public function getAllConfigs(?Context $context = null, ?array $configKeys = null): array;

    /**
     * Watches a config. The callback is called with the config's value each time the client
     * receives values that include the config, whether or not the value changed, and with the
     * in-code default value when the config is removed. It is not called when the watch is
     * registered. The client receives values during {@see initialize()}, so a watch registered
     * before it receives the value during it.
     *
     * Each value is evaluated as {@see getValue()} evaluates it and fires `configEvaluated`. A
     * callback that throws is logged at error level and ignored: the other watches still run.
     *
     * @template TDefaultValue
     *
     * @param string $configKey The config key to watch.
     * @param TDefaultValue $defaultValue The in-code default value. Its type decides the type of
     *     the values the callback receives, as for {@see getValue()}.
     * @param callable(TDefaultValue): void $callback Called with each value.
     * @param Context|null $context The context to evaluate the targeting rules for.
     *
     * @return \Closure(): void A function that removes this watch.
     *
     * @throws ConfigDirectorValidationException When the in-code default value is `null` or is not
     *     a `bool`, a `string`, an `int`, a `float` or an array.
     */
    public function watch(
        string $configKey,
        mixed $defaultValue,
        callable $callback,
        ?Context $context = null,
    ): \Closure;

    /**
     * Removes the first watch on a config key registered with the given callback, or every watch on
     * the key when no callback is given. The callback is found by identity: the same closure, or
     * the same `[$object, 'method']`.
     *
     * @param string $configKey The config key to stop watching.
     * @param callable|null $callback The callback of the watch to remove. Every watch on the key is
     *     removed when left out.
     */
    public function unwatch(string $configKey, ?callable $callback = null): void;

    /**
     * Removes every watch on every config key.
     */
    public function unwatchAll(): void;

    /**
     * Registers a handler for one of the events the client emits. A handler registered twice is
     * called twice.
     *
     * - `clientReady`: called with no argument when the client first receives values and becomes
     *   ready.
     * - `configsUpdated`: called with a {@see ConfigsUpdatedEvent} each time the client receives
     *   values, the first time included.
     * - `configEvaluated`: called with a {@see ConfigEvaluatedEvent} each time {@see getValue()},
     *   {@see evaluate()} or a watch evaluates a config, before the read returns.
     * - `connectionError`: called with a {@see ConnectionErrorEvent}. Nothing calls it yet: the
     *   client does not connect to ConfigDirector yet.
     *
     * The client receives values during {@see initialize()}. Each time, it calls the
     * `configsUpdated` handlers first, then the watches on the keys received or removed, then the
     * `clientReady` handlers when the client has just become ready. Inside these handlers and
     * watches the client is ready and reads return the values received. A handler that throws is
     * logged at error level and ignored: the other handlers still run and the read returns its
     * value.
     *
     * @param 'clientReady'|'configsUpdated'|'configEvaluated'|'connectionError' $eventName The event
     *     to handle.
     * @param callable $handler The handler to call.
     *
     * @throws ConfigDirectorValidationException When the event name is not one of the four above.
     */
    public function on(string $eventName, callable $handler): void;

    /**
     * Removes a handler registered for an event, or every handler of the event when no handler is
     * given. The handler is found by identity: the same closure, or the same `[$object, 'method']`.
     * When the handler was registered twice, one of its registrations is removed.
     *
     * @param 'clientReady'|'configsUpdated'|'configEvaluated'|'connectionError' $eventName The event
     *     whose handler to remove.
     * @param callable|null $handler The handler to remove. Every handler of the event is removed
     *     when left out.
     *
     * @throws ConfigDirectorValidationException When the event name is not one of the events
     *     {@see on()} lists.
     */
    public function off(string $eventName, ?callable $handler = null): void;

    /**
     * Disposes of the client. Afterwards the client is not ready, every read returns its in-code
     * default value, and every handler, hook and watch is removed. It is safe to call more than
     * once.
     */
    public function dispose(): void;
}
