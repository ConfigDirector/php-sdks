<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Connection;

enum UndeliveredPayload
{
    case WaitReachedTheTimeout;
    case FailedPollOnRecord;
    case ReasonLoggedAsAnError;
}
