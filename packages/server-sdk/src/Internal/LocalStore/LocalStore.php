<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\LocalStore;

use Psr\Log\LoggerInterface;

final class LocalStore
{
    private const int MILLISECONDS_TO_WAIT_FOR_THE_TELEMETRY_LOCK = 250;

    private bool $hasLoggedAnUnreadablePayloadFile = false;

    private bool $hasWarnedThatTelemetryWasNotKept = false;

    private function __construct(
        private readonly ?string $usableFolder,
        private readonly LoggerInterface $logger,
    ) {}

    public static function ofServerSdkKey(string $serverSdkKey, ?string $storagePath, LoggerInterface $logger): self
    {
        $folder = StoreFolder::openedFor($serverSdkKey, $storagePath);
        if ($folder->refusal === null) {
            return new self($folder->path, $logger);
        }

        $logger->error($folder->refusal->errorMessage(), ['folder' => $folder->path]);

        return new self(null, $logger);
    }

    public function storedPayload(): ?StoredPayload
    {
        if ($this->usableFolder === null) {
            return null;
        }

        $payloadFile = new PayloadFile($this->usableFolder);
        $storedPayload = $payloadFile->storedPayload();
        if ($storedPayload instanceof StoredPayload) {
            return $storedPayload;
        }
        if ($storedPayload === AbsentPayload::FileUnreadable && !$this->hasLoggedAnUnreadablePayloadFile) {
            $this->hasLoggedAnUnreadablePayloadFile = true;
            $this->logger->error(
                '[LocalStore] The values kept in {file} could not be read, so they will be fetched again.',
                ['file' => $payloadFile->path],
            );
        }

        return null;
    }

    public function pollingPause(string $polledUrl): ?PollingPause
    {
        return $this->usableFolder === null
            ? null
            : (new PollingPauseFile($this->usableFolder, $polledUrl))->pollingPause();
    }

    public function refreshLockAcquiredWithin(int $timeoutMilliseconds): ?RefreshLock
    {
        $refreshLock = $this->usableFolder === null
            ? null
            : RefreshLock::acquiredWithin($timeoutMilliseconds, $this->usableFolder, $this->logger);

        return $refreshLock instanceof RefreshLock ? $refreshLock : null;
    }

    /**
     * @template TOutcome
     *
     * @param \Closure(TelemetryLock): TOutcome $work
     *
     * @return TOutcome|null
     */
    public function whileHoldingTheTelemetryLock(\Closure $work): mixed
    {
        $telemetryLock = $this->usableFolder === null
            ? null
            : TelemetryLock::acquiredWithin(
                self::MILLISECONDS_TO_WAIT_FOR_THE_TELEMETRY_LOCK,
                $this->usableFolder,
                $this->warnOnceThatTelemetryWasNotKeptIn(...),
            );
        if ($telemetryLock === null) {
            return null;
        }

        try {
            return $work($telemetryLock);
        } finally {
            $telemetryLock->release();
        }
    }

    private function warnOnceThatTelemetryWasNotKeptIn(string $file): void
    {
        if ($this->hasWarnedThatTelemetryWasNotKept) {
            return;
        }

        $this->hasWarnedThatTelemetryWasNotKept = true;
        $this->logger->warning(
            '[LocalStore] {file} could not be written or locked, so the telemetry of this request was not kept.',
            ['file' => $file],
        );
    }

    /**
     * @param \Closure(int $remainingMilliseconds, RefreshLock $refreshLock): ?StoredPayload $fetchFreshPayload
     */
    public function storedPayloadFilledWithin(
        int $timeoutMilliseconds,
        \Closure $fetchFreshPayload,
    ): StoredPayload|UnfilledStore {
        $storedPayload = $this->storedPayload();
        if ($storedPayload !== null) {
            return $storedPayload;
        }
        if ($this->usableFolder === null) {
            return UnfilledStore::UnusableAsItsErrorLineSays;
        }

        $startedAt = hrtime(true);
        $refreshLock = RefreshLock::acquiredWithin($timeoutMilliseconds, $this->usableFolder, $this->logger);
        if (!$refreshLock instanceof RefreshLock) {
            return $this->storedPayload() ?? match ($refreshLock) {
                UnacquiredFileLock::HeldPastTheTimeout => UnfilledStore::RefreshLockHeldPastTheTimeout,
                UnacquiredFileLock::LockFileUnusable => UnfilledStore::UnusableAsItsErrorLineSays,
            };
        }

        try {
            $payloadStoredMeanwhile = $this->storedPayload();
            if ($payloadStoredMeanwhile !== null) {
                return $payloadStoredMeanwhile;
            }

            $waitedMilliseconds = intdiv(hrtime(true) - $startedAt, 1_000_000);
            $freshPayload = $fetchFreshPayload(max(0, $timeoutMilliseconds - $waitedMilliseconds), $refreshLock);
            if ($freshPayload === null) {
                return UnfilledStore::NothingFetched;
            }

            return $refreshLock->replaceStoredPayload($freshPayload)
                ? $freshPayload
                : UnfilledStore::UnusableAsItsErrorLineSays;
        } finally {
            $refreshLock->release();
        }
    }
}
