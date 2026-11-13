<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Telemetry;

enum ReportAnswerKind
{
    case Accepted;
    case RefusedUnrecoverably;
    case Refused;
    case Unanswered;
}
