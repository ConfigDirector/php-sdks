<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\LocalStore;

enum UnfilledStore
{
    case RefreshLockHeldPastTheTimeout;
    case NothingFetched;
    case UnusableAsItsErrorLineSays;
}
