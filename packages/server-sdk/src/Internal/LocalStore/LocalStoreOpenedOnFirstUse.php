<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\LocalStore;

use Psr\Log\LoggerInterface;

final class LocalStoreOpenedOnFirstUse
{
    private ?LocalStore $store = null;

    public function __construct(
        private readonly string $serverSdkKey,
        private readonly ?string $storagePath,
        private readonly LoggerInterface $logger,
    ) {}

    public function store(): LocalStore
    {
        return $this->store ??= LocalStore::ofServerSdkKey($this->serverSdkKey, $this->storagePath, $this->logger);
    }

    public function isOpened(): bool
    {
        return $this->store !== null;
    }
}
