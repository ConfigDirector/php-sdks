<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\LocalStore;

final readonly class PayloadFile
{
    private const int MAXIMUM_BYTES = 32 * 1024 * 1024;

    private const int ATTEMPTS_ON_WINDOWS_WHILE_ANOTHER_PROCESS_HAS_THE_FILE_OPEN = 20;

    private const int PAUSE_BETWEEN_ATTEMPTS_MICROSECONDS = 5_000;

    public string $path;

    private string $temporaryPath;

    public function __construct(string $storeFolder)
    {
        $this->path = $storeFolder . DIRECTORY_SEPARATOR . 'payload.json';
        $this->temporaryPath = $this->path . '.tmp';
    }

    public function storedPayload(): StoredPayload|AbsentPayload
    {
        clearstatcache(true, $this->path);
        if (!file_exists($this->path)) {
            return AbsentPayload::FileMissing;
        }

        $json = self::attemptedAgainOnWindows(
            fn(): string|false => file_get_contents($this->path, length: self::MAXIMUM_BYTES + 1),
        );
        if ($json === false || strlen($json) > self::MAXIMUM_BYTES) {
            return AbsentPayload::FileUnreadable;
        }

        return StoredPayload::fromJson($json) ?? AbsentPayload::FileUnreadable;
    }

    public function replaceWith(StoredPayload $storedPayload): bool
    {
        try {
            $json = $storedPayload->toJson();
        } catch (\JsonException) {
            return false;
        }

        return strlen($json) <= self::MAXIMUM_BYTES
            && $this->writeTemporaryFile($json)
            && self::attemptedAgainOnWindows(fn(): bool => rename($this->temporaryPath, $this->path));
    }

    private function writeTemporaryFile(string $json): bool
    {
        return SilencedWarnings::during(function () use ($json): bool {
            $temporaryFile = fopen($this->temporaryPath, 'cb');
            if ($temporaryFile === false) {
                return false;
            }

            try {
                chmod($this->temporaryPath, 0o600);

                return ftruncate($temporaryFile, 0)
                    && fwrite($temporaryFile, $json) === strlen($json)
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
