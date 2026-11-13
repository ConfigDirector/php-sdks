<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\LocalStore;

final class ExclusiveFileLock
{
    private const int PAUSE_BETWEEN_ATTEMPTS_MICROSECONDS = 5_000;

    /**
     * @param resource|null $lockFile
     */
    private function __construct(private mixed $lockFile) {}

    public static function acquiredWithin(int $timeoutMilliseconds, string $lockFilePath): self|UnacquiredFileLock
    {
        $lockFile = SilencedWarnings::during(static function () use ($lockFilePath) {
            $lockFile = fopen($lockFilePath, 'cb');
            chmod($lockFilePath, 0o600);

            return $lockFile;
        });
        if ($lockFile === false) {
            return UnacquiredFileLock::LockFileUnusable;
        }

        $deadline = hrtime(true) + $timeoutMilliseconds * 1_000_000;
        while (!flock($lockFile, LOCK_EX | LOCK_NB, $isHeldByAnotherProcess)) {
            if ($isHeldByAnotherProcess !== 1) {
                fclose($lockFile);

                return UnacquiredFileLock::LockFileUnusable;
            }
            if (hrtime(true) >= $deadline) {
                fclose($lockFile);

                return UnacquiredFileLock::HeldPastTheTimeout;
            }

            usleep(self::PAUSE_BETWEEN_ATTEMPTS_MICROSECONDS);
        }

        return new self($lockFile);
    }

    public function isReleased(): bool
    {
        return $this->lockFile === null;
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
