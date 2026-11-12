<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Evaluation;

final readonly class Rule
{
    /**
     * @param list<Condition> $conditions
     * @param list<Share>|null $shares
     */
    public function __construct(
        private string $id,
        public ?int $order,
        private string $type,
        private string $target,
        private ?string $value = null,
        private ?string $valueId = null,
        private ?string $variationId = null,
        private array $conditions = [],
        private ?array $shares = null,
    ) {}

    /**
     * @param array<string, Segment> $segments
     */
    public function serve(string $configId, EvaluationContext $context, array $segments): ServedValue|FallThrough
    {
        return match ($this->type) {
            'conditional' => $this->serveWhenEveryConditionHolds($configId, $context, $segments),
            'percentage' => $this->serveFromRollout($configId, $context),
            default => FallThrough::NotMatched,
        };
    }

    /**
     * @param array<string, Segment> $segments
     */
    private function serveWhenEveryConditionHolds(
        string $configId,
        EvaluationContext $context,
        array $segments,
    ): ServedValue|FallThrough {
        foreach ($this->conditions as $condition) {
            if (!$condition->holdsFor($context, $segments)) {
                return FallThrough::NotMatched;
            }
        }

        return match ($this->target) {
            'value' => $this->serveValue(),
            'percentage' => $this->serveFromRollout($configId, $context),
            default => FallThrough::NotMatched,
        };
    }

    private function serveValue(): ServedValue|FallThrough
    {
        if ($this->value === null) {
            return FallThrough::NotMatched;
        }

        return new ServedValue($this->value, $this->valueId, $this->variationId, null, ServedBy::MatchingRule);
    }

    private function serveFromRollout(string $configId, EvaluationContext $context): ServedValue|FallThrough
    {
        if ($this->shares === null) {
            return FallThrough::NotMatched;
        }
        if ($context->identifier === null) {
            return FallThrough::RolloutSkippedNoIdentifier;
        }

        $assignedPercentage = AssignedPercentage::of($configId, $context->identifier, $context->contextKind);
        $share = self::shareCovering($this->shares, $assignedPercentage);
        if ($share?->value === null) {
            return FallThrough::NotMatched;
        }

        return new ServedValue(
            $share->value,
            $share->valueId,
            $share->variationId,
            new RolloutShare($this->id, $share->id),
            ServedBy::MatchingRule,
        );
    }

    /**
     * @param list<Share> $shares
     */
    private static function shareCovering(array $shares, float $assignedPercentage): ?Share
    {
        $shareStart = 0.0;
        foreach ($shares as $share) {
            $shareEnd = $shareStart + $share->percentage;
            if ($assignedPercentage < $shareEnd) {
                return $share;
            }
            $shareStart = $shareEnd;
        }

        return null;
    }
}
