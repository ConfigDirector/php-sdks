<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Telemetry;

enum InvalidContextKindRecording
{
    case NewKindSample;
    case KnownKindSample;
    case FirstBeyondSampleLimit;
    case BeyondSampleLimit;
}
