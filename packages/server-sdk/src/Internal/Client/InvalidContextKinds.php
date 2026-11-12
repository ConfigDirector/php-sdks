<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Client;

final class InvalidContextKinds
{
    public const int DISTINCT_KIND_SAMPLE_LIMIT = 20;

    /** @var array<string, true> */
    private array $kindSamples = [];

    private bool $aKindWasBeyondTheSampleLimit = false;

    public function record(string $kindSample): InvalidContextKindRecording
    {
        if (isset($this->kindSamples[$kindSample])) {
            return InvalidContextKindRecording::KnownKindSample;
        }
        if (count($this->kindSamples) < self::DISTINCT_KIND_SAMPLE_LIMIT) {
            $this->kindSamples[$kindSample] = true;

            return InvalidContextKindRecording::NewKindSample;
        }
        if ($this->aKindWasBeyondTheSampleLimit) {
            return InvalidContextKindRecording::BeyondSampleLimit;
        }
        $this->aKindWasBeyondTheSampleLimit = true;

        return InvalidContextKindRecording::FirstBeyondSampleLimit;
    }
}
