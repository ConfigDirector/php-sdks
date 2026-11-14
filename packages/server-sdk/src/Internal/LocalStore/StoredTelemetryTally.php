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
        $fileContents = json_decode($json, associative: true);
        if (!is_array($fileContents)) {
            return null;
        }

        $collectingSince = $fileContents['collectingSince'] ?? null;
        $droppedEvaluations = $fileContents['droppedEvaluations'] ?? null;
        $droppedCapturedContexts = $fileContents['droppedCapturedContexts'] ?? null;

        $isWhatTheSdkStores = StoredNumber::isEpochMilliseconds($collectingSince)
            && StoredNumber::isCount($droppedEvaluations)
            && StoredNumber::isCount($droppedCapturedContexts);

        return $isWhatTheSdkStores
            ? new self($collectingSince, $droppedEvaluations, $droppedCapturedContexts)
            : null;
    }
}
