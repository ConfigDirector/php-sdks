<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\LocalStore;

use ConfigDirector\Internal\Identity\ServerSdkKey;
use Psr\Log\LoggerInterface;

final class LocalStoreOpenedOnFirstUse
{
    private ?LocalStore $store = null;

    private readonly ServerSdkKey $serverSdkKey;

    public function __construct(
        #[\SensitiveParameter]
        string $serverSdkKey,
        private readonly ?string $storagePath,
        private readonly LoggerInterface $logger,
    ) {
        $this->serverSdkKey = new ServerSdkKey($serverSdkKey);
    }

    public function store(): LocalStore
    {
        return $this->store ??= LocalStore::ofServerSdkKey(
            $this->serverSdkKey->text(),
            $this->storagePath,
            $this->logger,
        );
    }

    public function isOpened(): bool
    {
        return $this->store !== null;
    }
}
