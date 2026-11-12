<?php

declare(strict_types=1);

namespace ConfigDirector;

/**
 * One of the values a config can serve, such as the control or a treatment of an experiment.
 */
final readonly class Variation
{
    /**
     * @param string $id The ID ConfigDirector assigned to the variation, which stays the same when
     *     it is renamed.
     * @param string|null $name The variation's name, when it has one.
     */
    public function __construct(
        public string $id,
        public ?string $name = null,
    ) {}
}
