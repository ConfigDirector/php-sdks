<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\LocalStore;

final readonly class TelemetryFile
{
    private const int MAXIMUM_BYTES = 8 * 1024 * 1024;

    public string $path;

    private StoreFile $file;

    public function __construct(string $storeFolder)
    {
        $this->file = new StoreFile($storeFolder, 'telemetry.jsonl');
        $this->path = $this->file->path;
    }

    public function append(string $line): TelemetryFileAppend
    {
        return SilencedWarnings::during(function () use ($line): TelemetryFileAppend {
            $file = fopen($this->path, 'c+b');
            if ($file === false) {
                return TelemetryFileAppend::FileUnwritable;
            }

            try {
                chmod($this->path, 0o600);
                $sizeBytes = fseek($file, 0, SEEK_END) === 0 ? ftell($file) : false;
                if ($sizeBytes === false) {
                    return TelemetryFileAppend::FileUnwritable;
                }

                $endsInsideALine = $sizeBytes > 0 && !(fseek($file, -1, SEEK_END) === 0 && fread($file, 1) === "\n");
                $bytesToAppend = ($endsInsideALine ? "\n" : '') . $line . "\n";
                if ($sizeBytes + strlen($bytesToAppend) > self::MAXIMUM_BYTES) {
                    return TelemetryFileAppend::OverTheSizeLimit;
                }

                $isAppended = fseek($file, 0, SEEK_END) === 0
                    && fwrite($file, $bytesToAppend) === strlen($bytesToAppend)
                    && fflush($file);

                return $isAppended ? TelemetryFileAppend::Appended : TelemetryFileAppend::FileUnwritable;
            } finally {
                fclose($file);
            }
        });
    }

    /**
     * @return list<string>
     */
    public function lines(): array
    {
        $contents = $this->file->exists() ? $this->file->contentsOfAtMost(self::MAXIMUM_BYTES) : false;

        return $contents === false
            ? []
            : array_values(array_filter(explode("\n", $contents), static fn(string $line): bool => $line !== ''));
    }

    public function remove(): void
    {
        $this->file->remove();
    }
}
