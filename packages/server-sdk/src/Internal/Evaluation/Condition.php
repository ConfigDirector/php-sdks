<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Evaluation;

interface Condition
{
    /**
     * @param array<string, Segment> $segments
     */
    public function holdsFor(EvaluationContext $context, array $segments): bool;
}
