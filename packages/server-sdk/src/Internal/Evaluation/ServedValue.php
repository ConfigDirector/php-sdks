<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Evaluation;

final readonly class ServedValue
{
    public function __construct(
        public string $value,
        public ?string $valueId,
        public ?string $variationId,
        public ?RolloutShare $rolloutShare,
        public ServedBy $servedBy,
    ) {}
}
