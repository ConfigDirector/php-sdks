<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Telemetry;

use ConfigDirector\ConfigEvaluation;
use ConfigDirector\ConfigState;
use ConfigDirector\Internal\Value\InCodeDefaultValue;

final class DiscardedTelemetry implements ClientTelemetry
{
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
    public function recordTrackedEvent(TrackCall $call, \Closure $admittedContextOf): void {}

    #[\Override]
    public function storeAndSendWhatIsDueInALongRunningProcess(): void {}

    #[\Override]
    public function close(): void {}
}
