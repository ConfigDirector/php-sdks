<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\LocalStore;

final readonly class StreamingProcessLock
{
    private const int MILLISECONDS_A_REQUEST_MAY_BE_LOOKING_AT_THE_LOCK = 250;

    private function __construct(
        private ExclusiveFileLock $fileLock,
        private string $lockFilePath,
    ) {}

    public static function acquiredIn(string $storeFolder): self|UnacquiredFileLock
    {
        $lockFilePath = self::lockFilePathIn($storeFolder);
        $fileLock = ExclusiveFileLock::acquiredWithin(
            self::MILLISECONDS_A_REQUEST_MAY_BE_LOOKING_AT_THE_LOCK,
            $lockFilePath,
        );

        return $fileLock instanceof ExclusiveFileLock ? new self($fileLock, $lockFilePath) : $fileLock;
    }

    public static function isHeldIn(string $storeFolder): bool
    {
        $lockFilePath = self::lockFilePathIn($storeFolder);

        return SilencedWarnings::during(static function () use ($lockFilePath): bool {
            clearstatcache(true, $lockFilePath);
            $lockFile = is_file($lockFilePath) ? fopen($lockFilePath, 'rb') : false;
            if ($lockFile === false) {
                return false;
            }

            $isFree = flock($lockFile, LOCK_SH | LOCK_NB, $isHeldByAnotherProcess);
            fclose($lockFile);

            return !$isFree && $isHeldByAnotherProcess === 1;
        });
    }

    public function showTheLockFileIsInUse(): void
    {
        SilencedWarnings::during(fn(): bool => touch($this->lockFilePath));
    }

    public function release(): void
    {
        $this->fileLock->release();
    }

    public static function lockFilePathIn(string $storeFolder): string
    {
        return $storeFolder . DIRECTORY_SEPARATOR . 'streaming.lock';
    }
}
