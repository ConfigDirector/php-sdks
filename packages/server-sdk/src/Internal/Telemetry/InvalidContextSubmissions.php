<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Telemetry;

final class InvalidContextSubmissions
{
    public const int DISTINCT_KIND_SAMPLE_LIMIT = 20;

    /** @var array<string, int> */
    private array $countsByKindSample = [];

    private int $countBeyondTheSampleLimit = 0;

    public function countSubmissions(?string $kindSample, int $count): InvalidContextKindRecording
    {
        if ($kindSample !== null && isset($this->countsByKindSample[$kindSample])) {
            $this->countsByKindSample[$kindSample] += $count;

            return InvalidContextKindRecording::KnownKindSample;
        }
        if ($kindSample !== null && count($this->countsByKindSample) < self::DISTINCT_KIND_SAMPLE_LIMIT) {
            $this->countsByKindSample[$kindSample] = $count;

            return InvalidContextKindRecording::NewKindSample;
        }

        $isFirstBeyondTheSampleLimit = $this->countBeyondTheSampleLimit === 0;
        $this->countBeyondTheSampleLimit += $count;

        return $isFirstBeyondTheSampleLimit
            ? InvalidContextKindRecording::FirstBeyondSampleLimit
            : InvalidContextKindRecording::BeyondSampleLimit;
    }

    /**
     * @return array<string, int>
     */
    public function countsByKindSample(): array
    {
        return $this->countsByKindSample;
    }

    public function countBeyondTheSampleLimit(): int
    {
        return $this->countBeyondTheSampleLimit;
    }
}
