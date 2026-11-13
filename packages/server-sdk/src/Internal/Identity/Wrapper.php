<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Identity;

use ConfigDirector\ClientHooks;
use ConfigDirector\ConfigDirectorClient;
use ConfigDirector\ConnectionOptions;
use ConfigDirector\Internal\Client\ClientFactory;
use ConfigDirector\Metadata;
use ConfigDirector\TelemetryOptions;
use Psr\Log\LoggerInterface;

enum Wrapper: string
{
    private const string VERSION_WITHOUT_THE_PACKAGE_OF_THE_WRAPPER = '0.0.0-dev';

    case OpenFeatureServerProvider = 'php-openfeature-server-provider';

    public function createClient(
        ?string $serverSdkKey = null,
        ?Metadata $metadata = null,
        ?ConnectionOptions $connection = null,
        ?LoggerInterface $logger = null,
        ?string $logLevel = null,
        ?TelemetryOptions $telemetry = null,
        ?ClientHooks $hooks = null,
    ): ConfigDirectorClient {
        return ClientFactory::clientIdentifiedAs(
            SdkIdentity::ofTheWrapper($this),
            $serverSdkKey,
            $metadata,
            $connection,
            $logger,
            $logLevel,
            $telemetry,
            $hooks,
        );
    }

    public function versionOfItsPackage(): string
    {
        $versionConstant = match ($this) {
            self::OpenFeatureServerProvider => 'ConfigDirector\OpenFeature\Version::CURRENT',
        };
        $version = defined($versionConstant) ? constant($versionConstant) : null;

        return is_string($version) ? $version : self::VERSION_WITHOUT_THE_PACKAGE_OF_THE_WRAPPER;
    }
}
