<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Telemetry;

use ConfigDirector\Context;

final class TrackedEventAdmission
{
    public const int NAME_MAXIMUM_CHARACTERS = 150;

    public const int DETAILS_MAXIMUM_TOP_LEVEL_KEYS = 20;

    public const int DETAILS_MAXIMUM_JSON_BYTES = 1_024;

    private const int DETAILS_JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    private function __construct() {}

    public static function of(TrackCall $call, ?Context $admittedContext): AdmittedTrackedEvent|DroppedTrackedEvent
    {
        if (!self::isWithinTheCharacterLimit($call->eventName)) {
            return new DroppedTrackedEvent([TrackedEventWarning::NameOutsideTheCharacterLimit]);
        }

        $value = $call->value === null ? null : self::reportableValueOf($call->value);
        $detailsJson = $call->details === null ? null : self::reportableJsonOf($call->details);

        return new AdmittedTrackedEvent(
            $call->eventName,
            $admittedContext,
            is_float($value) ? $value : null,
            is_string($detailsJson) ? $detailsJson : null,
            array_values(array_filter(
                [$value, $detailsJson],
                static fn(float|string|TrackedEventWarning|null $field): bool => $field instanceof TrackedEventWarning,
            )),
        );
    }

    private static function isWithinTheCharacterLimit(string $eventName): bool
    {
        return preg_match('/^.{1,' . self::NAME_MAXIMUM_CHARACTERS . '}\z/su', $eventName) === 1;
    }

    private static function reportableValueOf(float $value): float|TrackedEventWarning
    {
        return is_finite($value) ? $value : TrackedEventWarning::ValueNotAFiniteNumber;
    }

    /**
     * @param array<array-key, mixed> $details
     */
    private static function reportableJsonOf(array $details): string|TrackedEventWarning
    {
        if ($details === []) {
            return '{}';
        }
        if (array_is_list($details) || count($details) > self::DETAILS_MAXIMUM_TOP_LEVEL_KEYS) {
            return TrackedEventWarning::DetailsNotReportable;
        }

        $detailsJson = self::jsonOf($details);

        return $detailsJson !== null && strlen($detailsJson) <= self::DETAILS_MAXIMUM_JSON_BYTES
            ? $detailsJson
            : TrackedEventWarning::DetailsNotReportable;
    }

    /**
     * @param non-empty-array<array-key, mixed> $details
     */
    private static function jsonOf(array $details): ?string
    {
        try {
            return json_encode($details, self::DETAILS_JSON_FLAGS);
        } catch (\Throwable) {
            return null;
        }
    }
}
