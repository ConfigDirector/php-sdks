<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\LocalStore;

final readonly class StoredTelemetryTally
{
    public function __construct(
        public int $collectingSinceEpochMilliseconds,
        public int $droppedEvaluations,
        public int $droppedCapturedContexts,
    ) {}

    public function toJson(): string
    {
        return json_encode(
            [
                'collectingSince' => $this->collectingSinceEpochMilliseconds,
                'droppedEvaluations' => $this->droppedEvaluations,
                'droppedCapturedContexts' => $this->droppedCapturedContexts,
            ],
            JSON_THROW_ON_ERROR,
        );
    }

    public static function fromJson(string $json): ?self
    {
        try {
            $fileContents = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($fileContents)) {
            return null;
        }

        $collectingSince = $fileContents['collectingSince'] ?? null;
        $droppedEvaluations = $fileContents['droppedEvaluations'] ?? null;
        $droppedCapturedContexts = $fileContents['droppedCapturedContexts'] ?? null;

        return is_int($collectingSince) && is_int($droppedEvaluations) && is_int($droppedCapturedContexts)
            ? new self($collectingSince, $droppedEvaluations, $droppedCapturedContexts)
            : null;
    }
}
