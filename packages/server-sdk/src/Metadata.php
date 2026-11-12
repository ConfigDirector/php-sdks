<?php

declare(strict_types=1);

namespace ConfigDirector;

/**
 * Metadata about your application. It is recommended you include these values when creating a
 * client so that you can use them in targeting rules.
 */
final readonly class Metadata
{
    /**
     * @param string|null $appName The name of your application.
     * @param string|null $appVersion The version of your application.
     */
    public function __construct(
        public ?string $appName = null,
        public ?string $appVersion = null,
    ) {}
}
