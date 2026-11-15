<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\LocalStore;

final readonly class ExclusiveTelemetryLock
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
        $fileLock = FileLock::exclusiveAcquiredWithin($timeoutMilliseconds, $files->lockFilePath);
        if ($fileLock === UnacquiredFileLock::LockFileUnusable) {
            $warnThatTelemetryWasNotKeptIn($files->lockFilePath);
        }

        return $fileLock instanceof FileLock ? new self($fileLock, $files, $warnThatTelemetryWasNotKeptIn) : null;
    }

    public function sendingPause(string $telemetryUrl): ?TelemetrySendingPause
    {
        return $this->files->sendingPauseFile($telemetryUrl)->sendingPause();
    }

    public function collectingSinceEpochMilliseconds(): ?int
    {
        return $this->files->collectingSinceEpochMilliseconds();
    }

    public function startCollectingAt(int $epochMilliseconds): null
    {
        if (!$this->files->startCollectingAt($epochMilliseconds)) {
            ($this->warnThatTelemetryWasNotKeptIn)($this->files->tally->path);
        }

        return null;
    }

    public function takeStoredTelemetry(int $nowEpochMilliseconds): StoredTelemetry
    {
        $this->files->telemetry->removeEveryFileMovedAside();
        $this->files->droppedCounts->removeEveryFileMovedAside();
        $storedTelemetry = new StoredTelemetry(
            $this->files->telemetry->movedAside(),
            $this->files->droppedCounts->movedAside(),
        );
        $this->files->startCollectingAt($nowEpochMilliseconds);

        return $storedTelemetry;
    }

    public function discardStoredTelemetry(): void
    {
        $this->files->telemetry->remove();
        $this->files->telemetry->removeEveryFileMovedAside();
        $this->files->droppedCounts->remove();
        $this->files->droppedCounts->removeEveryFileMovedAside();
        $this->files->tally->remove();
    }

    public function recordSendingPause(string $telemetryUrl, TelemetrySendingPause $sendingPause): void
    {
        $sendingPauseFile = $this->files->sendingPauseFile($telemetryUrl);
        if (!$sendingPauseFile->replaceWith($sendingPause)) {
            ($this->warnThatTelemetryWasNotKeptIn)($sendingPauseFile->path);
        }
    }

    public function release(): void
    {
        $this->fileLock->release();
    }
}
