<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Connection;

enum InMemoryConnectionState
{
    case AwaitingInitialization;
    case Connected;
    case HoldingAnInitialization;
    case Closed;
}
