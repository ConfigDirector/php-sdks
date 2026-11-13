<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\LocalStore;

enum UnacquiredFileLock
{
    case HeldPastTheTimeout;
    case LockFileUnusable;
}
