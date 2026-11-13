<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Telemetry;

use ConfigDirector\ConfigEvaluation;
use ConfigDirector\ConfigState;
use ConfigDirector\Internal\Value\InCodeDefaultValue;
use ConfigDirector\Testing\TrackedEvent;
use Psr\Log\LoggerInterface;

final class TrackedEventRecordingTelemetry implements ClientTelemetry
{
    /** @var list<TrackedEvent> */
    private array $trackedEvents = [];

    public function __construct(private readonly LoggerInterface $logger) {}

    #[\Override]
    public function recordEvaluation(
        ConfigEvaluation $evaluation,
        InCodeDefaultValue $inCodeDefaultValue,
        ?ConfigState $stateServed,
    ): void {}

    #[\Override]
    public function recordInvalidContextKind(string $kindSample): InvalidContextKindRecording
    {
        return InvalidContextKindRecording::NewKindSample;
    }

    #[\Override]
    public function recordTrackedEvent(TrackCall $call, \Closure $admittedContextOf): void
    {
        $admission = TrackedEventAdmission::of($call, $admittedContextOf($call->context));
        foreach ($admission->warnings as $warning) {
            $this->logger->warning($warning->message());
        }
        if ($admission instanceof AdmittedTrackedEvent) {
            $this->trackedEvents[] = new TrackedEvent(
                $admission->eventName,
                $admission->context,
                $admission->value,
                self::detailsAsTheSdkWouldSend($admission->detailsJson),
            );
        }
    }

    #[\Override]
    public function storeAndSendWhatIsDueInALongRunningProcess(): void {}

    #[\Override]
    public function close(): void {}

    /**
     * @return list<TrackedEvent>
     */
    public function trackedEvents(): array
    {
        return $this->trackedEvents;
    }

    public function clearTrackedEvents(): void
    {
        $this->trackedEvents = [];
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private static function detailsAsTheSdkWouldSend(?string $detailsJson): ?array
    {
        $details = $detailsJson === null ? null : json_decode($detailsJson, true);

        return is_array($details) ? $details : null;
    }
}
