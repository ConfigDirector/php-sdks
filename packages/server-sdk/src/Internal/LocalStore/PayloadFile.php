<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\LocalStore;

final readonly class PayloadFile
{
    private const int MAXIMUM_BYTES = 32 * 1024 * 1024;

    public string $path;

    private StoreFile $file;

    public function __construct(string $storeFolder)
    {
        $this->file = new StoreFile($storeFolder, 'payload.json');
        $this->path = $this->file->path;
    }

    public function storedPayload(): StoredPayload|AbsentPayload
    {
        if (!$this->file->exists()) {
            return AbsentPayload::FileMissing;
        }

        $json = $this->file->contentsOfAtMost(self::MAXIMUM_BYTES);
        if ($json === false) {
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

        return strlen($json) <= self::MAXIMUM_BYTES && $this->file->replaceContentsWith($json);
    }
}
