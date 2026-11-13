<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Evaluation;

final readonly class SegmentCondition implements Condition
{
    public function __construct(private string $operator, public string $segmentId) {}

    #[\Override]
    public function holdsFor(EvaluationContext $context, array $segments): bool
    {
        $segment = $segments[$this->segmentId] ?? null;
        if ($segment === null) {
            return false;
        }

        return match (strtolower($this->operator)) {
            'in' => $segment->contains($context),
            'not in' => !$segment->contains($context),
            default => false,
        };
    }
}
