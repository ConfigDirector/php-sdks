<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\LocalStore;

final readonly class LinesMovedAside
{
    private const int TIMES_THE_MAXIMUM_OF_THE_FILE_THAT_ARE_READ = 2;

    /**
     * @param resource $openedFile
     */
    private function __construct(
        private mixed $openedFile,
        private StoreFile $fileMovedAside,
        private int $maximumBytesRead,
    ) {}

    public static function openedFrom(StoreFile $fileMovedAside, int $maximumBytesOfTheFile): ?self
    {
        $openedFile = SilencedWarnings::during(static fn() => fopen($fileMovedAside->path, 'rb'));

        return $openedFile === false
            ? null
            : new self(
                $openedFile,
                $fileMovedAside,
                self::TIMES_THE_MAXIMUM_OF_THE_FILE_THAT_ARE_READ * $maximumBytesOfTheFile,
            );
    }

    /**
     * @return \Generator<int, non-empty-string>
     */
    public function linesReadOnce(): \Generator
    {
        $bytesLeftToRead = $this->maximumBytesRead;
        try {
            while ($bytesLeftToRead > 0 && ($line = $this->nextLineOfAtMost($bytesLeftToRead)) !== false) {
                $bytesLeftToRead -= strlen($line) + strlen("\n");
                if ($line !== '') {
                    yield $line;
                }
            }
        } finally {
            fclose($this->openedFile);
            $this->fileMovedAside->remove();
        }
    }

    /**
     * @param int<1, max> $maximumBytes
     */
    private function nextLineOfAtMost(int $maximumBytes): string|false
    {
        return SilencedWarnings::during(
            fn(): string|false => stream_get_line($this->openedFile, $maximumBytes, "\n"),
        );
    }
}
