<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Connection;

use ConfigDirector\ConfigDirectorInitializationException;
use ConfigDirector\ConfigDirectorValidationException;
use ConfigDirector\Internal\Evaluation\TargetingRules;
use ConfigDirector\Internal\LocalStore\SilencedWarnings;
use ConfigDirector\Internal\Value\EncodedNativeValue;
use ConfigDirector\Internal\Value\UnservableValue;

final readonly class OfflineConnection implements Connection
{
    private const string STREAM_SCHEME_PATTERN = '/^[A-Za-z][A-Za-z0-9+.-]+:/';

    /**
     * @param \Closure(): ServerSdkPayload $payloadOfTheOfflineValues
     */
    private function __construct(private \Closure $payloadOfTheOfflineValues) {}

    /**
     * @param array<array-key, mixed> $values
     */
    public static function servingTheValues(array $values): self
    {
        try {
            $payload = self::payloadServing($values);
        } catch (UnservableValue $unservable) {
            throw new ConfigDirectorValidationException(
                "The offline values could not be loaded. {$unservable->getMessage()}",
                previous: $unservable,
            );
        }

        return new self(static fn(): ServerSdkPayload => $payload);
    }

    public static function servingTheValuesOfTheFile(string $path): self
    {
        return new self(static function () use ($path): ServerSdkPayload {
            $values = self::valuesInTheFile($path);
            try {
                return self::payloadServing($values);
            } catch (UnservableValue $unservable) {
                throw new ConfigDirectorInitializationException(
                    "The offline values file '{$path}' could not be loaded. {$unservable->getMessage()}",
                    previous: $unservable,
                );
            }
        });
    }

    #[\Override]
    public function payloadForInitialization(): ServerSdkPayload
    {
        return ($this->payloadOfTheOfflineValues)();
    }

    #[\Override]
    public function payloadFoundByARead(): null
    {
        return null;
    }

    #[\Override]
    public function close(): void {}

    /**
     * @param array<array-key, mixed> $values
     *
     * @throws UnservableValue
     */
    private static function payloadServing(array $values): ServerSdkPayload
    {
        $configs = [];
        foreach ($values as $configKey => $value) {
            if (trim((string) $configKey) === '') {
                throw new UnservableValue('Invalid config key. The key of a value cannot be empty.');
            }

            $encodedValue = EncodedNativeValue::of($value, (string) $configKey);
            $configs[(string) $configKey] = new ConfigDefinition(
                "offline:{$configKey}",
                (string) $configKey,
                $encodedValue->configType,
                new TargetingRules($encodedValue->servedText, null, null, []),
            );
        }

        return new ServerSdkPayload($configs, []);
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function valuesInTheFile(string $path): array
    {
        if (preg_match(self::STREAM_SCHEME_PATTERN, $path) === 1) {
            throw new ConfigDirectorInitializationException(
                "The offline values file '{$path}' could not be read: it is a URL rather than a path to a file on this "
                . 'host, and an offline client never connects.',
            );
        }

        $contents = SilencedWarnings::during(static fn(): string|false => file_get_contents($path), $readWarning);
        if ($contents === false || $readWarning !== null) {
            throw new ConfigDirectorInitializationException(
                "The offline values file '{$path}' could not be read: " . ($readWarning ?? 'it could not be opened.'),
            );
        }

        try {
            $document = json_decode($contents, false, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new ConfigDirectorInitializationException(
                "The offline values file '{$path}' is not valid JSON: {$exception->getMessage()}",
                previous: $exception,
            );
        }
        if (!$document instanceof \stdClass) {
            throw new ConfigDirectorInitializationException(sprintf(
                "The offline values file '%s' must hold a JSON object with one value per config key, but holds %s.",
                $path,
                match (true) {
                    $document === null => 'null',
                    is_array($document) => 'an array',
                    is_bool($document) => 'a boolean',
                    is_string($document) => 'a string',
                    default => 'a number',
                },
            ));
        }

        return get_object_vars($document);
    }
}
