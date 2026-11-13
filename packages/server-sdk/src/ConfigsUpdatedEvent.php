<?php

declare(strict_types=1);

namespace ConfigDirector;

/**
 * What a `configsUpdated` handler receives each time the client receives values that are new or
 * changed.
 */
final readonly class ConfigsUpdatedEvent
{
    /**
     * @param list<string> $keys The keys of every config, the first time the client receives
     *     values. After that, the keys of the configs that are new or changed: a config counts as
     *     changed when anything the SDK reads of it changed, such as its targeting rules or its
     *     variations, or when a segment its targeting rules use changed.
     * @param list<string> $removedKeys The keys of the configs the client held that it no longer
     *     received, which now return their in-code default value. Empty when nothing was removed.
     */
    public function __construct(
        public array $keys,
        public array $removedKeys,
    ) {}
}
