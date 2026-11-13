<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\LocalStore;

final readonly class PollingPauseFile
{
    private const int MAXIMUM_BYTES = 65_536;

    public string $path;

    private StoreFile $file;

    public function __construct(string $storeFolder, string $polledUrl)
    {
        $this->file = new StoreFile($storeFolder, 'polling-pause-' . hash('sha256', $polledUrl) . '.json');
        $this->path = $this->file->path;
    }

    public function pollingPause(): ?PollingPause
    {
        $json = $this->file->exists() ? $this->file->contentsOfAtMost(self::MAXIMUM_BYTES) : false;

        return $json === false ? null : PollingPause::fromJson($json);
    }

    public function replaceWith(PollingPause $pollingPause): bool
    {
        return $this->file->replaceContentsWith($pollingPause->toJson());
    }
}
