<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\LocalStore;

final readonly class TelemetryLock
{
    private const int TALLY_MAXIMUM_BYTES = 65_536;

    private TelemetryFile $telemetryFile;

    private StoreFile $tallyFile;

    /**
     * @param \Closure(string $file): void $warnThatTelemetryWasNotKeptIn
     */
    private function __construct(
        private ExclusiveFileLock $fileLock,
        private string $storeFolder,
        private \Closure $warnThatTelemetryWasNotKeptIn,
    ) {
        $this->telemetryFile = new TelemetryFile($storeFolder);
        $this->tallyFile = new StoreFile($storeFolder, 'telemetry-tally.json');
    }

    /**
     * @param \Closure(string $file): void $warnThatTelemetryWasNotKeptIn
     */
    public static function acquiredWithin(
        int $timeoutMilliseconds,
        string $storeFolder,
        \Closure $warnThatTelemetryWasNotKeptIn,
    ): ?self {
        $lockFilePath = $storeFolder . DIRECTORY_SEPARATOR . 'telemetry.lock';
        $fileLock = ExclusiveFileLock::acquiredWithin($timeoutMilliseconds, $lockFilePath);
        if (!$fileLock instanceof ExclusiveFileLock) {
            $warnThatTelemetryWasNotKeptIn($lockFilePath);

            return null;
        }

        return new self($fileLock, $storeFolder, $warnThatTelemetryWasNotKeptIn);
    }

    public function sendingPause(string $telemetryUrl): ?TelemetrySendingPause
    {
        return (new TelemetrySendingPauseFile($this->storeFolder, $telemetryUrl))->sendingPause();
    }

    public function addTelemetryOfAClient(string $telemetryFileLine, int $collectingSinceEpochMilliseconds): bool
    {
        $startsTheTally = $this->tally() === null;
        $firstTally = new StoredTelemetryTally($collectingSinceEpochMilliseconds, 0, 0);
        if ($startsTheTally && !$this->tallyFile->replaceContentsWith($firstTally->toJson())) {
            ($this->warnThatTelemetryWasNotKeptIn)($this->tallyFile->path);

            return false;
        }

        $append = $this->telemetryFile->append($telemetryFileLine);
        if ($append === TelemetryFileAppend::FileUnwritable) {
            ($this->warnThatTelemetryWasNotKeptIn)($this->telemetryFile->path);
        }

        return $append === TelemetryFileAppend::Appended;
    }

    public function countAsDropped(int $evaluations, int $capturedContexts, int $nowEpochMilliseconds): void
    {
        $tally = $this->tally();
        $this->tallyFile->replaceContentsWith((new StoredTelemetryTally(
            $tally->collectingSinceEpochMilliseconds ?? $nowEpochMilliseconds,
            ($tally->droppedEvaluations ?? 0) + $evaluations,
            ($tally->droppedCapturedContexts ?? 0) + $capturedContexts,
        ))->toJson());
    }

    public function collectingSinceEpochMilliseconds(): ?int
    {
        return $this->tally()?->collectingSinceEpochMilliseconds;
    }

    public function takeStoredTelemetry(int $nowEpochMilliseconds): StoredTelemetry
    {
        $tally = $this->tally();
        $storedTelemetry = new StoredTelemetry(
            $this->telemetryFile->lines(),
            $tally->droppedEvaluations ?? 0,
            $tally->droppedCapturedContexts ?? 0,
        );
        $this->telemetryFile->remove();
        $this->tallyFile->replaceContentsWith((new StoredTelemetryTally($nowEpochMilliseconds, 0, 0))->toJson());

        return $storedTelemetry;
    }

    public function discardStoredTelemetry(): void
    {
        $this->telemetryFile->remove();
        $this->tallyFile->remove();
    }

    public function recordSendingPause(string $telemetryUrl, TelemetrySendingPause $sendingPause): void
    {
        $sendingPauseFile = new TelemetrySendingPauseFile($this->storeFolder, $telemetryUrl);
        if (!$sendingPauseFile->replaceWith($sendingPause)) {
            ($this->warnThatTelemetryWasNotKeptIn)($sendingPauseFile->path);
        }
    }

    public function release(): void
    {
        $this->fileLock->release();
    }

    private function tally(): ?StoredTelemetryTally
    {
        $json = $this->tallyFile->exists() ? $this->tallyFile->contentsOfAtMost(self::TALLY_MAXIMUM_BYTES) : false;

        return $json === false ? null : StoredTelemetryTally::fromJson($json);
    }
}
