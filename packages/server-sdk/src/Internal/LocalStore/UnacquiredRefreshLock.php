<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\LocalStore;

enum UnacquiredRefreshLock
{
    case HeldPastTheTimeout;
    case LockFileUnusable;
}
