<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Telemetry;

enum TrackedEventWarning
{
    case NameOutsideTheCharacterLimit;
    case ValueNotAFiniteNumber;
    case DetailsNotReportable;
    case ReportAlreadyFull;

    public function messageOfTheFirstInABatch(): string
    {
        return $this->message()
            . ' Further tracked events like it are not logged until the next telemetry batch is sent.';
    }

    public function message(): string
    {
        return match ($this) {
            self::NameOutsideTheCharacterLimit => sprintf(
                '[ConfigDirectorClient] A tracked event was not reported because its name does not have 1 to %d '
                . 'characters.',
                TrackedEventAdmission::NAME_MAXIMUM_CHARACTERS,
            ),
            self::ValueNotAFiniteNumber => '[ConfigDirectorClient] A tracked event was reported without its value '
                . 'because the value is not a finite number.',
            self::DetailsNotReportable => sprintf(
                '[ConfigDirectorClient] A tracked event was reported without its details because they are not an '
                . 'object, have more than %d top-level keys, are larger than %d bytes as JSON, or cannot be written '
                . 'as JSON.',
                TrackedEventAdmission::DETAILS_MAXIMUM_TOP_LEVEL_KEYS,
                TrackedEventAdmission::DETAILS_MAXIMUM_JSON_BYTES,
            ),
            self::ReportAlreadyFull => sprintf(
                '[ConfigDirectorClient] A tracked event was not reported because %d tracked events are already '
                . 'waiting for the next telemetry batch.',
                TrackedEventQueue::TRACKED_EVENTS_PER_REPORT,
            ),
        };
    }
}
