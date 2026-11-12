<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Evaluation;

final readonly class RolloutShare
{
    public function __construct(public string $ruleId, public string $shareId) {}
}
