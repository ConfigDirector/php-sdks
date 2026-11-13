<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\LocalStore;

use Psr\Log\LoggerInterface;

final class RefreshLock
{
    private const string UNWRITABLE_FILE_MESSAGE = '[LocalStore] {file} could not be written or locked, so the local '
        . 'store keeps the values it had.';

    private function __construct(
        private readonly ExclusiveFileLock $fileLock,
        private readonly string $storeFolder,
        private readonly PayloadFile $payloadFile,
        private readonly LoggerInterface $logger,
    ) {}

    public static function acquiredWithin(
        int $timeoutMilliseconds,
        string $storeFolder,
        LoggerInterface $logger,
    ): self|UnacquiredFileLock {
        $lockFilePath = $storeFolder . DIRECTORY_SEPARATOR . 'lock';
        $fileLock = ExclusiveFileLock::acquiredWithin($timeoutMilliseconds, $lockFilePath);
        if ($fileLock === UnacquiredFileLock::LockFileUnusable) {
            $logger->error(self::UNWRITABLE_FILE_MESSAGE, ['file' => $lockFilePath]);
        }

        return $fileLock instanceof ExclusiveFileLock
            ? new self($fileLock, $storeFolder, new PayloadFile($storeFolder), $logger)
            : $fileLock;
    }

    public function replaceStoredPayload(StoredPayload $storedPayload): bool
    {
        if ($this->fileLock->isReleased()) {
            throw new \LogicException('A released refresh lock cannot replace the stored payload.');
        }

        $isReplaced = $this->payloadFile->replaceWith($storedPayload);
        if (!$isReplaced) {
            $this->logger->error(self::UNWRITABLE_FILE_MESSAGE, ['file' => $this->payloadFile->path]);
        }

        return $isReplaced;
    }

    public function recordPollingPause(string $polledUrl, PollingPause $pollingPause): bool
    {
        if ($this->fileLock->isReleased()) {
            throw new \LogicException('A released refresh lock cannot record a polling pause.');
        }

        $pollingPauseFile = new PollingPauseFile($this->storeFolder, $polledUrl);
        $isRecorded = $pollingPauseFile->replaceWith($pollingPause);
        if (!$isRecorded) {
            $this->logger->error(self::UNWRITABLE_FILE_MESSAGE, ['file' => $pollingPauseFile->path]);
        }

        return $isRecorded;
    }

    public function release(): void
    {
        $this->fileLock->release();
    }
}
