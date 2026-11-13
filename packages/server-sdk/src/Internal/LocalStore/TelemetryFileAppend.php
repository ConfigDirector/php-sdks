<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\LocalStore;

enum TelemetryFileAppend
{
    case Appended;
    case OverTheSizeLimit;
    case FileUnwritable;
}
