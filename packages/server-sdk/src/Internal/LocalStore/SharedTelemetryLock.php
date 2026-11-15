<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\LocalStore;

final readonly class SharedTelemetryLock
{
    /**
     * @param \Closure(string $file): void $warnThatTelemetryWasNotKeptIn
     */
    private function __construct(
        private FileLock $fileLock,
        private TelemetryFiles $files,
        private \Closure $warnThatTelemetryWasNotKeptIn,
    ) {}

    /**
     * @param \Closure(string $file): void $warnThatTelemetryWasNotKeptIn
     */
    public static function acquiredWithin(
        int $timeoutMilliseconds,
        string $storeFolder,
        \Closure $warnThatTelemetryWasNotKeptIn,
    ): ?self {
        $files = new TelemetryFiles($storeFolder);
        $fileLock = FileLock::sharedAcquiredWithin($timeoutMilliseconds, $files->lockFilePath);
        if (!$fileLock instanceof FileLock) {
            $warnThatTelemetryWasNotKeptIn($files->lockFilePath);

            return null;
        }

        return new self($fileLock, $files, $warnThatTelemetryWasNotKeptIn);
    }

    public function sendingPause(string $telemetryUrl): ?TelemetrySendingPause
    {
        return $this->files->sendingPauseFile($telemetryUrl)->sendingPause();
    }

    public function addTelemetryOfAClient(string $telemetryFileLine): bool
    {
        $append = $this->files->telemetry->append($telemetryFileLine);
        if ($append === LineAppend::FileUnwritable) {
            ($this->warnThatTelemetryWasNotKeptIn)($this->files->telemetry->path);
        }

        return $append === LineAppend::Appended;
    }

    public function countAsDropped(int $evaluations, int $capturedContexts): void
    {
        $this->files->droppedCounts->append((new DroppedTelemetryCounts($evaluations, $capturedContexts))->toJson());
    }

    public function collectingSinceEpochMilliseconds(): ?int
    {
        return $this->files->collectingSinceEpochMilliseconds();
    }

    public function release(): void
    {
        $this->fileLock->release();
    }
}
