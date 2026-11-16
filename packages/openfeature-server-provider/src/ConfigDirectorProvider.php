<?php

declare(strict_types=1);

namespace ConfigDirector\OpenFeature;

use ConfigDirector\ClientHooks;
use ConfigDirector\ConfigDirectorClient;
use ConfigDirector\ConfigDirectorInitializationException;
use ConfigDirector\ConfigDirectorValidationException;
use ConfigDirector\ConnectionOptions;
use ConfigDirector\Context;
use ConfigDirector\Internal\Identity\Wrapper;
use ConfigDirector\Metadata;
use ConfigDirector\OpenFeature\Internal\ResolutionDetailsByReason;
use ConfigDirector\TelemetryOptions;
use OpenFeature\implementation\provider\AbstractProvider;
use OpenFeature\interfaces\flags\EvaluationContext;
use OpenFeature\interfaces\provider\ResolutionDetails;
use Psr\Log\LoggerInterface;

/**
 * An OpenFeature provider backed by the ConfigDirector PHP server SDK. Register it with the
 * OpenFeature API and read values through an OpenFeature client:
 *
 * ```
 * $api = OpenFeatureAPI::getInstance();
 * $api->setProvider(new ConfigDirectorProvider(
 *     serverSdkKey: 'YOUR-SERVER-SDK-KEY',
 *     metadata: new Metadata(appName: 'my-awesome-app', appVersion: '1.0.0'),
 * ));
 *
 * $newCheckout = $api->getClient()->getBooleanValue('new-checkout', false);
 * ```
 *
 * Targeting rules are evaluated on this host, so a read makes no network call.
 *
 * The evaluation context becomes the ConfigDirector {@see Context}: the targeting key, or failing
 * that an `id` attribute, becomes its `id`, and the `kind`, `name`, `traits` and `anonymous`
 * attributes become the fields of those names. Other attributes are not used, so put the context's
 * traits inside `traits`. An `id` or a `name` that is a number, a boolean or a date is read as
 * text, and a date inside `traits` as ISO 8601 text, so that date conditions can compare it. A
 * context whose `kind` is not a string is ignored, and the flag is evaluated as if no context had
 * been given.
 *
 * Evaluation details carry the name of the variation served as the `variant`, or the ID of the
 * value served when the variation has no name or the config has no variations.
 *
 * The OpenFeature PHP SDK has no provider events, no tracking and no shutdown yet, so this
 * provider emits no events, has no `track()`, and is not shut down: its client disposes of itself
 * when the script ends or when nothing refers to the provider any longer. OpenFeature's
 * `setLogger()` changes nothing here; give the logger as `logger`.
 */
final class ConfigDirectorProvider extends AbstractProvider
{
    protected static string $NAME = 'ConfigDirectorProvider';

    private readonly ConfigDirectorClient $client;

    /**
     * Creates the provider and its ConfigDirector client, and reads the values ConfigDirector
     * serves, as {@see ConfigDirectorClient::initialize()} does: from the local store this host's
     * PHP processes share, or, when it holds none yet, from ConfigDirector, waiting up to the
     * connection timeout. When no values arrive in time, flags resolve to their in-code default
     * value with the error code `PROVIDER_NOT_READY` until values arrive, and the SDK logs why.
     * This constructor does not throw for that.
     *
     * @param string|null $serverSdkKey The ConfigDirector server SDK key. This is a secret value,
     *     do not commit it in your source code. Stack traces show `SensitiveParameterValue` in
     *     its place. It can be left out in offline mode only.
     * @param Metadata|null $metadata Metadata about your application. Including `appName` and
     *     `appVersion` is recommended, so that you can use them in targeting rules.
     * @param ConnectionOptions|null $connection Connection options.
     * @param LoggerInterface|null $logger A PSR-3 logger. Without one the SDK writes warnings and
     *     errors through `error_log`.
     * @param string|null $logLevel The lowest level the SDK writes when no `$logger` is given, as
     *     a PSR-3 level such as `Psr\Log\LogLevel::DEBUG`. Defaults to `warning`. It has no effect
     *     when a `$logger` is given.
     * @param TelemetryOptions|null $telemetry Telemetry tuning.
     * @param ClientHooks|null $hooks Handlers for the events the ConfigDirector client emits.
     *
     * @throws ConfigDirectorValidationException For a setting that
     *     {@see \ConfigDirector\ConfigDirector::createClient()} rejects, such as a missing server
     *     SDK key outside offline mode.
     * @throws ConfigDirectorInitializationException In offline mode, when the values of
     *     `connection.file` cannot be loaded.
     */
    public function __construct(
        #[\SensitiveParameter]
        ?string $serverSdkKey = null,
        ?Metadata $metadata = null,
        ?ConnectionOptions $connection = null,
        ?LoggerInterface $logger = null,
        ?string $logLevel = null,
        ?TelemetryOptions $telemetry = null,
        ?ClientHooks $hooks = null,
    ) {
        $this->client = Wrapper::OpenFeatureServerProvider->createClient(
            $serverSdkKey,
            $metadata,
            $connection,
            $logger,
            $logLevel,
            $telemetry,
            $hooks,
        );
        $this->client->initialize();
    }

    #[\Override]
    public function resolveBooleanValue(
        string $flagKey,
        bool $defaultValue,
        ?EvaluationContext $context = null,
    ): ResolutionDetails {
        return $this->resolutionOf($flagKey, $defaultValue, $context);
    }

    #[\Override]
    public function resolveStringValue(
        string $flagKey,
        string $defaultValue,
        ?EvaluationContext $context = null,
    ): ResolutionDetails {
        return $this->resolutionOf($flagKey, $defaultValue, $context);
    }

    #[\Override]
    public function resolveIntegerValue(
        string $flagKey,
        int $defaultValue,
        ?EvaluationContext $context = null,
    ): ResolutionDetails {
        return $this->resolutionOf($flagKey, $defaultValue, $context);
    }

    #[\Override]
    public function resolveFloatValue(
        string $flagKey,
        float $defaultValue,
        ?EvaluationContext $context = null,
    ): ResolutionDetails {
        return $this->resolutionOf($flagKey, $defaultValue, $context);
    }

    /**
     * @param array<array-key, mixed> $defaultValue
     */
    #[\Override]
    public function resolveObjectValue(
        string $flagKey,
        array $defaultValue,
        ?EvaluationContext $context = null,
    ): ResolutionDetails {
        return $this->resolutionOf($flagKey, $defaultValue, $context);
    }

    /**
     * @param bool|string|int|float|array<array-key, mixed> $defaultValue
     */
    private function resolutionOf(
        string $flagKey,
        bool|string|int|float|array $defaultValue,
        ?EvaluationContext $context,
    ): ResolutionDetails {
        return ResolutionDetailsByReason::ofTheEvaluation(
            $this->client->evaluate($flagKey, $defaultValue, self::contextOf($context)),
        );
    }

    private static function contextOf(?EvaluationContext $evaluationContext): ?Context
    {
        if ($evaluationContext === null) {
            return null;
        }

        $attributes = $evaluationContext->getAttributes();
        $kind = $attributes->get('kind');
        if ($kind !== null && !is_string($kind)) {
            return null;
        }

        $traits = $attributes->get('traits');

        return new Context(
            id: $evaluationContext->getTargetingKey() ?? self::textOfTheAttribute($attributes->get('id')),
            kind: $kind,
            name: self::textOfTheAttribute($attributes->get('name')),
            traits: is_array($traits) ? self::withDatesAsText($traits) : [],
            anonymous: $attributes->get('anonymous') === true,
        );
    }

    private static function textOfTheAttribute(mixed $value): ?string
    {
        return match (true) {
            is_string($value) => $value,
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value), is_float($value) => (string) $value,
            $value instanceof \DateTimeInterface => self::iso8601TextOf($value),
            default => null,
        };
    }

    /**
     * @param array<array-key, mixed> $traits
     *
     * @return array<string, mixed>
     */
    private static function withDatesAsText(array $traits): array
    {
        $traitsByName = [];
        foreach ($traits as $name => $trait) {
            $traitsByName[(string) $name] = match (true) {
                $trait instanceof \DateTimeInterface => self::iso8601TextOf($trait),
                is_array($trait) => self::withDatesAsText($trait),
                default => $trait,
            };
        }

        return $traitsByName;
    }

    private static function iso8601TextOf(\DateTimeInterface $date): string
    {
        return $date->format($date->format('u') === '000000' ? 'Y-m-d\TH:i:sP' : 'Y-m-d\TH:i:s.uP');
    }
}
