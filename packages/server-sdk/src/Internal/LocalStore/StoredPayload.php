<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\LocalStore;

final readonly class StoredPayload
{
    /**
     * @param array<array-key, mixed> $payload
     */
    public function __construct(
        public array $payload,
        public int $confirmedFreshAtEpochMilliseconds,
        public string $sessionId,
    ) {}

    public function epochMillisecondsFreshUntil(int $pollingIntervalMilliseconds, int $nowEpochMilliseconds): int
    {
        $wasConfirmedFreshByAClockSetBackSince = $this->confirmedFreshAtEpochMilliseconds > $nowEpochMilliseconds;

        return $wasConfirmedFreshByAClockSetBackSince
            ? 0
            : $this->confirmedFreshAtEpochMilliseconds + $pollingIntervalMilliseconds;
    }

    public function stampOfItsWrite(): string
    {
        $timestamp = $this->payload['timestamp'] ?? null;

        return $this->confirmedFreshAtEpochMilliseconds . ' ' . (is_string($timestamp) ? $timestamp : '');
    }

    /**
     * @param array<array-key, mixed> $payload
     */
    public static function holdsAConfigsObject(array $payload): bool
    {
        return is_array($payload['configs'] ?? null);
    }

    /**
     * @param array<array-key, mixed> $receivedPayload
     *
     * @return array<array-key, mixed>
     */
    public static function payloadAfterReceiving(array $receivedPayload, ?self $storedPayload): array
    {
        if ($storedPayload === null || !self::isADelta($receivedPayload)) {
            return $receivedPayload;
        }

        $mergedPayload = array_replace($storedPayload->payload, $receivedPayload);
        $mergedPayload['kind'] = 'full';
        foreach (['configs', 'segments'] as $payloadSection) {
            $storedSection = $storedPayload->payload[$payloadSection] ?? null;
            $receivedSection = $receivedPayload[$payloadSection] ?? null;
            $mergedPayload[$payloadSection] = array_replace(
                is_array($storedSection) ? $storedSection : [],
                is_array($receivedSection) ? $receivedSection : [],
            );
        }

        return $mergedPayload;
    }

    /**
     * @param array<array-key, mixed> $payload
     */
    public static function isADelta(array $payload): bool
    {
        return ($payload['kind'] ?? null) === 'delta';
    }

    public function toJson(): string
    {
        return json_encode(
            [
                'confirmedFreshAt' => $this->confirmedFreshAtEpochMilliseconds,
                'sessionId' => $this->sessionId,
                'payload' => $this->payload,
            ],
            JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
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

        $payload = $fileContents['payload'] ?? null;
        $confirmedFreshAt = $fileContents['confirmedFreshAt'] ?? null;
        $sessionId = $fileContents['sessionId'] ?? null;
        $isAServerSdkPayload = is_array($payload) && self::holdsAConfigsObject($payload);
        if (!$isAServerSdkPayload || !is_int($confirmedFreshAt) || !is_string($sessionId) || $sessionId === '') {
            return null;
        }

        return new self($payload, $confirmedFreshAt, $sessionId);
    }
}
