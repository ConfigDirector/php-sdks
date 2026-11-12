<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Evaluation;

enum FallThrough
{
    case NotMatched;
    case RolloutSkippedNoIdentifier;
}
