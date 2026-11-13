<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\LocalStore;

use Psr\Log\LoggerInterface;

final class RefreshLock
{
    private const int PAUSE_BETWEEN_ATTEMPTS_MICROSECONDS = 5_000;

    private const string UNWRITABLE_FILE_MESSAGE = '[LocalStore] {file} could not be written or locked, so the local '
        . 'store keeps the values it had.';

    /**
     * @param resource|null $lockFile
     */
    private function __construct(
        private mixed $lockFile,
        private readonly string $storeFolder,
        private readonly PayloadFile $payloadFile,
        private readonly LoggerInterface $logger,
    ) {}

    public static function acquiredWithin(
        int $timeoutMilliseconds,
        string $storeFolder,
        LoggerInterface $logger,
    ): self|UnacquiredRefreshLock {
        $lockFilePath = $storeFolder . DIRECTORY_SEPARATOR . 'lock';
        $lockFile = SilencedWarnings::during(static function () use ($lockFilePath) {
            $lockFile = fopen($lockFilePath, 'cb');
            chmod($lockFilePath, 0o600);

            return $lockFile;
        });
        if ($lockFile === false) {
            $logger->error(self::UNWRITABLE_FILE_MESSAGE, ['file' => $lockFilePath]);

            return UnacquiredRefreshLock::LockFileUnusable;
        }

        $deadline = hrtime(true) + $timeoutMilliseconds * 1_000_000;
        while (!flock($lockFile, LOCK_EX | LOCK_NB, $isHeldByAnotherProcess)) {
            if ($isHeldByAnotherProcess !== 1) {
                fclose($lockFile);
                $logger->error(self::UNWRITABLE_FILE_MESSAGE, ['file' => $lockFilePath]);

                return UnacquiredRefreshLock::LockFileUnusable;
            }
            if (hrtime(true) >= $deadline) {
                fclose($lockFile);

                return UnacquiredRefreshLock::HeldPastTheTimeout;
            }

            usleep(self::PAUSE_BETWEEN_ATTEMPTS_MICROSECONDS);
        }

        return new self($lockFile, $storeFolder, new PayloadFile($storeFolder), $logger);
    }

    public function replaceStoredPayload(StoredPayload $storedPayload): bool
    {
        if ($this->lockFile === null) {
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
        if ($this->lockFile === null) {
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
        if ($this->lockFile === null) {
            return;
        }

        flock($this->lockFile, LOCK_UN);
        fclose($this->lockFile);
        $this->lockFile = null;
    }
}
