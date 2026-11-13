<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Polling;

enum PollAnswerKind
{
    case Payload;
    case Unchanged;
    case Throttled;
    case RefusedUnrecoverably;
    case Refused;
    case Unanswered;
    case TimedOut;
    case Unreadable;
}
