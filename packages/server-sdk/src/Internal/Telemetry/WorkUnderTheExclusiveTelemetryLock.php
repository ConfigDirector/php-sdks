<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Telemetry;

enum WorkUnderTheExclusiveTelemetryLock
{
    case Nothing;
    case StartingTheTally;
    case TakingTheStoredTelemetry;
}
