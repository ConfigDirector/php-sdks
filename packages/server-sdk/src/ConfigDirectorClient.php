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
     * Disposes of the client. Afterwards the client is not ready and every read returns its
     * in-code default value. It is safe to call more than once.
     */
    public function dispose(): void;
}
