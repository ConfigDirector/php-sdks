<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Telemetry;

use ConfigDirector\ConfigEvaluation;
use ConfigDirector\ConfigState;
use ConfigDirector\Context;
use ConfigDirector\Internal\Value\InCodeDefaultValue;

interface ClientTelemetry
{
    /**
     * @param ConfigEvaluation<bool|string|int|float|array<array-key, mixed>> $evaluation
     */
    public function recordEvaluation(
        ConfigEvaluation $evaluation,
        InCodeDefaultValue $inCodeDefaultValue,
        ?ConfigState $stateServed,
    ): void;

    public function recordInvalidContextKind(string $kindSample): InvalidContextKindRecording;

    /**
     * @param \Closure(?Context): ?Context $admittedContextOf
     */
    public function recordTrackedEvent(TrackCall $call, \Closure $admittedContextOf): void;

    public function storeAndSendWhatIsDueInALongRunningProcess(): void;

    public function close(): void;
}
