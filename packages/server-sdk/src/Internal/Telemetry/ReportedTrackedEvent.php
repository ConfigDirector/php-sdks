<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Telemetry;

use ConfigDirector\Internal\Evaluation\ScalarText;

final class ReportedTrackedEvent
{
    private const int JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

    private function __construct() {}

    public static function entryJson(AdmittedTrackedEvent $trackedEvent, int $happenedAtEpochMilliseconds): string
    {
        $reportedContext = ReportedContext::of($trackedEvent->context);
        $members = ['"name":' . self::jsonTextOf($trackedEvent->eventName)];
        if ($reportedContext !== null) {
            $members[] = '"contextKind":' . self::jsonTextOf($reportedContext->kind);
            $members[] = '"contextIdentifier":' . self::jsonTextOf($reportedContext->identifier);
        }
        if ($trackedEvent->value !== null) {
            $members[] = '"value":' . ScalarText::of($trackedEvent->value);
        }
        if ($trackedEvent->detailsJson !== null) {
            $members[] = '"details":' . $trackedEvent->detailsJson;
        }
        $members[] = '"happenedAt":' . self::jsonTextOf(ReportedTime::of($happenedAtEpochMilliseconds));

        return '{' . implode(',', $members) . '}';
    }

    private static function jsonTextOf(string $text): string
    {
        return (string) json_encode($text, self::JSON_FLAGS);
    }
}
