<?php

declare(strict_types=1);

namespace ConfigDirector;

/**
 * What a `connectionError` handler receives.
 */
final readonly class ConnectionErrorEvent
{
    /**
     * @param \Throwable $error The error the connection failed with.
     */
    public function __construct(
        public \Throwable $error,
    ) {}
}
