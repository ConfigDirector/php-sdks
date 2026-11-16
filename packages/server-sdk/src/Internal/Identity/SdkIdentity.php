<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Identity;

use ConfigDirector\Metadata;
use ConfigDirector\Version;

final readonly class SdkIdentity
{
    private function __construct(
        private string $sdkName,
        private string $sdkVersion,
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
        return self::metaContextNaming($this->sdkName, $this->sdkVersion, $metadata->appName, $metadata->appVersion);
    }

    /**
     * @return array<string, string>
     */
    public static function metaContextNaming(
        string $sdkName,
        string $sdkVersion,
        ?string $appName,
        ?string $appVersion,
    ): array {
        return array_filter(
            ['sdkName' => $sdkName, 'sdkVersion' => $sdkVersion, 'appName' => $appName, 'appVersion' => $appVersion],
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
