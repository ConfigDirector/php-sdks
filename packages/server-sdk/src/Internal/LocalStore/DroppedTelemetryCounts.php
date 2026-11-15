<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\LocalStore;

final readonly class DroppedTelemetryCounts
{
    public function __construct(
        public int $evaluations,
        public int $capturedContexts,
    ) {}

    public function plus(self $counts): self
    {
        return new self(
            $this->evaluations + $counts->evaluations,
            $this->capturedContexts + $counts->capturedContexts,
        );
    }

    public function toJson(): string
    {
        return json_encode(
            ['droppedEvaluations' => $this->evaluations, 'droppedCapturedContexts' => $this->capturedContexts],
            JSON_THROW_ON_ERROR,
        );
    }

    public static function fromJson(string $json): ?self
    {
        $fileLine = json_decode($json, associative: true);
        if (!is_array($fileLine)) {
            return null;
        }

        $evaluations = $fileLine['droppedEvaluations'] ?? null;
        $capturedContexts = $fileLine['droppedCapturedContexts'] ?? null;

        return StoredNumber::isCount($evaluations) && StoredNumber::isCount($capturedContexts)
            ? new self($evaluations, $capturedContexts)
            : null;
    }
}
