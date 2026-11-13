<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Telemetry;

final class TrackedEventQueue
{
    public const int TRACKED_EVENTS_PER_REPORT = 1_000;

    /** @var list<string> */
    private array $entryJsons = [];

    private int $entriesLeftOut = 0;

    /** @var array<string, true> */
    private array $warningsRaised = [];

    /**
     * @return list<TrackedEventWarning> The warnings this queue raises for the first time.
     */
    public function keepTrackedEvent(
        AdmittedTrackedEvent|DroppedTrackedEvent $admission,
        int $happenedAtEpochMilliseconds,
    ): array {
        $warnings = $admission instanceof AdmittedTrackedEvent
            ? $this->warningsOfKeepingThe($admission, $happenedAtEpochMilliseconds)
            : $admission->warnings;

        return array_values(array_filter($warnings, $this->isRaisedForTheFirstTime(...)));
    }

    public function keepEntryUnlessFull(string $entryJson): bool
    {
        if (count($this->entryJsons) >= self::TRACKED_EVENTS_PER_REPORT) {
            $this->entriesLeftOut++;

            return false;
        }

        $this->entryJsons[] = $entryJson;

        return true;
    }

    /**
     * @return list<string>
     */
    public function entryJsons(): array
    {
        return $this->entryJsons;
    }

    public function entriesLeftOut(): int
    {
        return $this->entriesLeftOut;
    }

    /**
     * @return list<TrackedEventWarning>
     */
    private function warningsOfKeepingThe(AdmittedTrackedEvent $trackedEvent, int $happenedAtEpochMilliseconds): array
    {
        $entryJson = ReportedTrackedEvent::entryJson($trackedEvent, $happenedAtEpochMilliseconds);

        return $this->keepEntryUnlessFull($entryJson)
            ? $trackedEvent->warnings
            : [TrackedEventWarning::ReportAlreadyFull];
    }

    private function isRaisedForTheFirstTime(TrackedEventWarning $warning): bool
    {
        $isFirst = !isset($this->warningsRaised[$warning->name]);
        $this->warningsRaised[$warning->name] = true;

        return $isFirst;
    }
}
