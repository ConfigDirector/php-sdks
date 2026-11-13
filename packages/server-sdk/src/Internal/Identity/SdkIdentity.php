<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Identity;

use ConfigDirector\Metadata;
use ConfigDirector\Version;

final readonly class SdkIdentity
{
    private function __construct(
        public string $sdkName,
        public string $sdkVersion,
    ) {}

    public static function ofTheServerSdk(): self
    {
        return new self('php-server-sdk', Version::CURRENT);
    }

    public static function ofTheWrapper(Wrapper $wrapper): self
    {
        return new self($wrapper->value, $wrapper->versionOfItsPackage());
    }

    /**
     * @return array<string, string>
     */
    public function metaContextOfAnApplicationWith(Metadata $metadata): array
    {
        return array_filter(
            [
                'sdkName' => $this->sdkName,
                'sdkVersion' => $this->sdkVersion,
                'appName' => $metadata->appName,
                'appVersion' => $metadata->appVersion,
            ],
            static fn(?string $value): bool => $value !== null,
        );
    }

    /**
     * @return non-empty-string
     */
    public function userAgent(): string
    {
        return "{$this->sdkName}/{$this->sdkVersion}";
    }
}
