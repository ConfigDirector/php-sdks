<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\LocalStore;

final class ExclusiveFileLock
{
    private const int LONGEST_PAUSE_AFTER_THE_FIRST_ATTEMPT_MICROSECONDS = 250;

    private const int LONGEST_PAUSE_BETWEEN_ATTEMPTS_MICROSECONDS = 1_000;

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
        $longestPauseMicroseconds = self::LONGEST_PAUSE_AFTER_THE_FIRST_ATTEMPT_MICROSECONDS;
        while (!flock($lockFile, LOCK_EX | LOCK_NB, $isHeldByAnotherProcess)) {
            if ($isHeldByAnotherProcess !== 1) {
                fclose($lockFile);

                return UnacquiredFileLock::LockFileUnusable;
            }
            if (hrtime(true) >= $deadline) {
                fclose($lockFile);

                return UnacquiredFileLock::HeldPastTheTimeout;
            }

            usleep(random_int(intdiv($longestPauseMicroseconds, 2), $longestPauseMicroseconds));
            $longestPauseMicroseconds = min(
                2 * $longestPauseMicroseconds,
                self::LONGEST_PAUSE_BETWEEN_ATTEMPTS_MICROSECONDS,
            );
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
