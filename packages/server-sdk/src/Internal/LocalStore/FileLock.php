<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\LocalStore;

final class FileLock
{
    private const int LARGEST_RANDOM_NUMBER_DRAWN = 1_000_000;

    /**
     * @param resource|null $lockFile
     */
    private function __construct(private mixed $lockFile) {}

    public static function exclusiveAcquiredWithin(
        int $timeoutMilliseconds,
        string $lockFilePath,
    ): self|UnacquiredFileLock {
        return self::acquiredWithin($timeoutMilliseconds, $lockFilePath, LOCK_EX);
    }

    public static function sharedAcquiredWithin(int $timeoutMilliseconds, string $lockFilePath): self|UnacquiredFileLock
    {
        return self::acquiredWithin($timeoutMilliseconds, $lockFilePath, LOCK_SH);
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

    /**
     * @param int<1, 2> $sharedOrExclusive
     */
    private static function acquiredWithin(
        int $timeoutMilliseconds,
        string $lockFilePath,
        int $sharedOrExclusive,
    ): self|UnacquiredFileLock {
        $lockFile = SilencedWarnings::during(static function () use ($lockFilePath) {
            $lockFile = fopen($lockFilePath, 'cb');
            chmod($lockFilePath, 0o600);

            return $lockFile;
        });
        if ($lockFile === false) {
            return UnacquiredFileLock::LockFileUnusable;
        }

        $reasonItIsNotLocked = self::reasonTheOpenFileIsNotLockedWithin(
            $timeoutMilliseconds,
            $lockFile,
            $sharedOrExclusive,
        );
        if ($reasonItIsNotLocked !== null) {
            fclose($lockFile);

            return $reasonItIsNotLocked;
        }

        return new self($lockFile);
    }

    /**
     * @param resource $openFile
     * @param int<1, 2> $sharedOrExclusive
     */
    private static function reasonTheOpenFileIsNotLockedWithin(
        int $timeoutMilliseconds,
        mixed $openFile,
        int $sharedOrExclusive,
    ): ?UnacquiredFileLock {
        $deadline = hrtime(true) + $timeoutMilliseconds * 1_000_000;
        for ($attempt = 1; !flock($openFile, $sharedOrExclusive | LOCK_NB, $isHeldByAnotherProcess); $attempt++) {
            if ($isHeldByAnotherProcess !== 1) {
                return UnacquiredFileLock::LockFileUnusable;
            }
            if (hrtime(true) >= $deadline) {
                return UnacquiredFileLock::HeldPastTheTimeout;
            }

            $randomFraction = random_int(0, self::LARGEST_RANDOM_NUMBER_DRAWN) / self::LARGEST_RANDOM_NUMBER_DRAWN;
            usleep(LockAttemptPause::microsecondsAfterAttempt($attempt, $randomFraction));
        }

        return null;
    }
}
