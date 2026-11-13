<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\LocalStore;

final readonly class StoreFile
{
    private const int ATTEMPTS_ON_WINDOWS_WHILE_ANOTHER_PROCESS_HAS_THE_FILE_OPEN = 20;

    private const int PAUSE_BETWEEN_ATTEMPTS_MICROSECONDS = 5_000;

    public string $path;

    private string $temporaryPath;

    public function __construct(string $storeFolder, string $fileName)
    {
        $this->path = $storeFolder . DIRECTORY_SEPARATOR . $fileName;
        $this->temporaryPath = $this->path . '.tmp';
    }

    public function exists(): bool
    {
        clearstatcache(true, $this->path);

        return file_exists($this->path);
    }

    /**
     * @param int<0, max> $maximumBytes
     */
    public function contentsOfAtMost(int $maximumBytes): string|false
    {
        $contents = self::attemptedAgainOnWindows(
            fn(): string|false => file_get_contents($this->path, length: $maximumBytes + 1),
        );

        return $contents === false || strlen($contents) > $maximumBytes ? false : $contents;
    }

    public function replaceContentsWith(string $contents): bool
    {
        return $this->writeTemporaryFile($contents)
            && self::attemptedAgainOnWindows(fn(): bool => rename($this->temporaryPath, $this->path));
    }

    private function writeTemporaryFile(string $contents): bool
    {
        return SilencedWarnings::during(function () use ($contents): bool {
            $temporaryFile = fopen($this->temporaryPath, 'cb');
            if ($temporaryFile === false) {
                return false;
            }

            try {
                chmod($this->temporaryPath, 0o600);

                return ftruncate($temporaryFile, 0)
                    && fwrite($temporaryFile, $contents) === strlen($contents)
                    && fflush($temporaryFile);
            } finally {
                fclose($temporaryFile);
            }
        });
    }

    /**
     * @template TResult
     *
     * @param \Closure(): (TResult|false) $filesystemCall
     *
     * @return TResult|false
     */
    private static function attemptedAgainOnWindows(\Closure $filesystemCall): mixed
    {
        $attemptsAllowed = PHP_OS_FAMILY === 'Windows'
            ? self::ATTEMPTS_ON_WINDOWS_WHILE_ANOTHER_PROCESS_HAS_THE_FILE_OPEN
            : 1;
        for ($attempt = 1; ; $attempt++) {
            $result = SilencedWarnings::during($filesystemCall);
            if ($result !== false || $attempt >= $attemptsAllowed) {
                return $result;
            }

            usleep(self::PAUSE_BETWEEN_ATTEMPTS_MICROSECONDS);
        }
    }
}
