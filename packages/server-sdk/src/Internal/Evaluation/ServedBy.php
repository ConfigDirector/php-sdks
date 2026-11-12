<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Evaluation;

enum ServedBy
{
    case MatchingRule;
    case DefaultTargetingRule;
    case DefaultTargetingRuleAfterSkippedRollout;
}
