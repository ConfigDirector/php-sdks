<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\LocalStore;

final readonly class AppendedLinesFile
{
    private const string START_OF_THE_SUFFIX_OF_A_FILE_MOVED_ASIDE = '.taken-';

    private const int MODE_READABLE_BY_ITS_OWNER_ONLY = 0o600;

    private const int MILLISECONDS_TO_WAIT_FOR_THE_WRITE_OF_ANOTHER_PROCESS = 250;

    public string $path;

    private StoreFile $file;

    public function __construct(
        private string $storeFolder,
        private string $fileName,
        private int $maximumBytes,
    ) {
        $this->file = new StoreFile($storeFolder, $fileName);
        $this->path = $this->file->path;
    }

    public function append(string $line): LineAppend
    {
        return SilencedWarnings::during(function () use ($line): LineAppend {
            $file = fopen($this->path, 'ab');
            if ($file === false) {
                return LineAppend::FileUnwritable;
            }

            try {
                $isFreeToWrite = !self::appendsOfSeveralProcessesCanOverwriteEachOther()
                    || FileLock::reasonTheOpenFileIsNotLockedExclusiveWithin(
                        self::MILLISECONDS_TO_WAIT_FOR_THE_WRITE_OF_ANOTHER_PROCESS,
                        $file,
                    ) === null;
                if (!$isFreeToWrite) {
                    return LineAppend::FileUnwritable;
                }

                $status = fstat($file);
                if ($status === false) {
                    return LineAppend::FileUnwritable;
                }
                if (($status['mode'] & 0o777) !== self::MODE_READABLE_BY_ITS_OWNER_ONLY) {
                    chmod($this->path, self::MODE_READABLE_BY_ITS_OWNER_ONLY);
                }

                $lineBetweenLineBreaks = "\n" . $line . "\n";
                if ($status['size'] + strlen($lineBetweenLineBreaks) > $this->maximumBytes) {
                    return LineAppend::OverTheSizeLimit;
                }

                $isAppended = fwrite($file, $lineBetweenLineBreaks) === strlen($lineBetweenLineBreaks)
                    && fflush($file);

                return $isAppended ? LineAppend::Appended : LineAppend::FileUnwritable;
            } finally {
                fclose($file);
            }
        });
    }

    public function movedAside(): ?LinesMovedAside
    {
        if (!$this->file->isARegularFile()) {
            return null;
        }

        $fileMovedAside = $this->file->movedTo(
            $this->fileName . self::START_OF_THE_SUFFIX_OF_A_FILE_MOVED_ASIDE . bin2hex(random_bytes(8)),
        );

        return $fileMovedAside === null ? null : LinesMovedAside::openedFrom($fileMovedAside, $this->maximumBytes);
    }

    public function removeEveryFileMovedAside(): void
    {
        $entries = SilencedWarnings::during(fn(): array|false => scandir($this->storeFolder));
        foreach ($entries === false ? [] : $entries as $entry) {
            if (str_starts_with($entry, $this->fileName . self::START_OF_THE_SUFFIX_OF_A_FILE_MOVED_ASIDE)) {
                SilencedWarnings::during(fn(): bool => unlink($this->storeFolder . DIRECTORY_SEPARATOR . $entry));
            }
        }
    }

    public function remove(): void
    {
        $this->file->remove();
    }

    private static function appendsOfSeveralProcessesCanOverwriteEachOther(): bool
    {
        return PHP_OS_FAMILY === 'Windows';
    }
}
