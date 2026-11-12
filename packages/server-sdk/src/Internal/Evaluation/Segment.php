<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Evaluation;

final readonly class Segment
{
    /**
     * @param list<list<AttributeCondition>> $conditionGroups
     */
    public function __construct(private array $conditionGroups) {}

    public function contains(EvaluationContext $context): bool
    {
        foreach ($this->conditionGroups as $conditionGroup) {
            if (self::everyConditionHolds($conditionGroup, $context)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<AttributeCondition> $conditionGroup
     */
    private static function everyConditionHolds(array $conditionGroup, EvaluationContext $context): bool
    {
        foreach ($conditionGroup as $condition) {
            if (!$condition->holdsFor($context, [])) {
                return false;
            }
        }

        return true;
    }
}
