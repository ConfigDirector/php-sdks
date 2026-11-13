<?php

declare(strict_types=1);

namespace ConfigDirector;

/**
 * What a `configEvaluated` handler receives each time a config is evaluated.
 */
final readonly class ConfigEvaluatedEvent
{
    /**
     * @param ConfigEvaluation<bool|string|int|float|array<array-key, mixed>> $evaluation The
     *     evaluation, as {@see ConfigDirectorClient::evaluate()} returns it.
     */
    public function __construct(
        public ConfigEvaluation $evaluation,
    ) {}
}
