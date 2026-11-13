<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\LocalStore;

final readonly class TelemetrySendingPauseFile
{
    private const int MAXIMUM_BYTES = 65_536;

    public string $path;

    private StoreFile $file;

    public function __construct(string $storeFolder, string $telemetryUrl)
    {
        $this->file = new StoreFile($storeFolder, 'telemetry-pause-' . hash('sha256', $telemetryUrl) . '.json');
        $this->path = $this->file->path;
    }

    public function sendingPause(): ?TelemetrySendingPause
    {
        $json = $this->file->exists() ? $this->file->contentsOfAtMost(self::MAXIMUM_BYTES) : false;

        return $json === false ? null : TelemetrySendingPause::fromJson($json);
    }

    public function replaceWith(TelemetrySendingPause $sendingPause): bool
    {
        return $this->file->replaceContentsWith($sendingPause->toJson());
    }
}
