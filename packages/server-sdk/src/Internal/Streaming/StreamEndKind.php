<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Streaming;

enum StreamEndKind
{
    case Unreachable;
    case Throttled;
    case RefusedUnrecoverably;
    case Refused;
    case Dropped;
    case PayloadNotStored;
}
