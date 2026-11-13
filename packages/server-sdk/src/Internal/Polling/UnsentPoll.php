<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Polling;

enum UnsentPoll
{
    case NotDue;
    case NoTimeLeft;
    case PollingPauseNotRecorded;
}
