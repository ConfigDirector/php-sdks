<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Streaming;

use ConfigDirector\Internal\Identity\SessionId;
use ConfigDirector\Internal\LocalStore\LocalStoreOpenedOnFirstUse;
use ConfigDirector\Internal\LocalStore\StoredPayload;
use ConfigDirector\Internal\Time\Clock;

final class LocalStoreFedByTheStream
{
    private const int MILLISECONDS_BETWEEN_CONFIRMATIONS_OF_FRESHNESS = 30_000;

    private const int MILLISECONDS_TO_WAIT_FOR_THE_REFRESH_LOCK = 5_000;

    private int $confirmationDueAtMonotonicMilliseconds = 0;

    public function __construct(
        private readonly LocalStoreOpenedOnFirstUse $localStore,
        private readonly Clock $clock,
    ) {}

    /**
     * @param array<array-key, mixed> $payload
     */
    public function storePayloadReceived(array $payload): bool
    {
        $store = $this->localStore->store();
        $refreshLock = $store->refreshLockAcquiredWithin(self::MILLISECONDS_TO_WAIT_FOR_THE_REFRESH_LOCK);
        if ($refreshLock === null) {
            return false;
        }

        try {
            $storedPayload = $store->storedPayload();
            $isADeltaOfNothing = $storedPayload === null && StoredPayload::isADelta($payload);
            $isStored = !$isADeltaOfNothing && $refreshLock->replaceStoredPayload(new StoredPayload(
                StoredPayload::payloadAfterReceiving($payload, $storedPayload),
                $this->clock->epochMilliseconds(),
                $storedPayload->sessionId ?? SessionId::newlyGenerated(),
            ));
        } finally {
            $refreshLock->release();
        }
        if ($isStored) {
            $this->expectTheNextConfirmationAfterTheInterval();
        }

        return $isStored;
    }

    public function confirmTheStoredPayloadIsFreshWhenDue(): void
    {
        if ($this->clock->monotonicMilliseconds() < $this->confirmationDueAtMonotonicMilliseconds) {
            return;
        }

        $store = $this->localStore->store();
        $refreshLock = $store->refreshLockAcquiredWithin(self::MILLISECONDS_TO_WAIT_FOR_THE_REFRESH_LOCK);
        $this->expectTheNextConfirmationAfterTheInterval();
        if ($refreshLock === null) {
            return;
        }

        try {
            $storedPayload = $store->storedPayload();
            if ($storedPayload !== null) {
                $refreshLock->replaceStoredPayload(new StoredPayload(
                    $storedPayload->payload,
                    $this->clock->epochMilliseconds(),
                    $storedPayload->sessionId,
                ));
            }
        } finally {
            $refreshLock->release();
        }
    }

    private function expectTheNextConfirmationAfterTheInterval(): void
    {
        $this->confirmationDueAtMonotonicMilliseconds = $this->clock->monotonicMilliseconds()
            + self::MILLISECONDS_BETWEEN_CONFIRMATIONS_OF_FRESHNESS;
    }
}
