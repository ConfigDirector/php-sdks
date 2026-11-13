<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Client;

use ConfigDirector\ClientHooks;
use ConfigDirector\ConfigDirectorValidationException;

enum ClientEventName: string
{
    case ClientReady = 'clientReady';
    case ConfigsUpdated = 'configsUpdated';
    case ConfigEvaluated = 'configEvaluated';
    case ConnectionError = 'connectionError';

    public static function named(string $eventName): self
    {
        return self::tryFrom($eventName) ?? throw new ConfigDirectorValidationException(sprintf(
            "Unknown event '%s'. The event must be one of %s.",
            $eventName,
            implode(', ', array_map(static fn(self $known): string => "'{$known->value}'", self::cases())),
        ));
    }

    /**
     * @return list<mixed>
     */
    public function hooksIn(ClientHooks $hooks): array
    {
        $hooksOfThisEvent = match ($this) {
            self::ClientReady => $hooks->clientReady,
            self::ConfigsUpdated => $hooks->configsUpdated,
            self::ConfigEvaluated => $hooks->configEvaluated,
            self::ConnectionError => $hooks->connectionError,
        };

        return match (true) {
            $hooksOfThisEvent === null => [],
            is_callable($hooksOfThisEvent) => [$hooksOfThisEvent],
            default => array_values($hooksOfThisEvent),
        };
    }
}
