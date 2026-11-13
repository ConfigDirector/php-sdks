<?php

declare(strict_types=1);

namespace ConfigDirector;

/**
 * What a `configsUpdated` handler receives each time the client receives values.
 */
final readonly class ConfigsUpdatedEvent
{
    /**
     * @param list<string> $keys The keys of every config the client received.
     * @param list<string> $removedKeys The keys of the configs the client held that it no longer
     *     received, which now return their in-code default value. Empty when nothing was removed.
     */
    public function __construct(
        public array $keys,
        public array $removedKeys,
    ) {}
}
