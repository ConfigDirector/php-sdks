<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Evaluation;

final readonly class Share
{
    public function __construct(
        public string $id,
        public float $percentage,
        public ?string $value,
        public ?string $valueId = null,
        public ?string $variationId = null,
    ) {}
}
