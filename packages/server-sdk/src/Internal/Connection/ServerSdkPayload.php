<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Connection;

use ConfigDirector\Internal\Evaluation\Segment;

final readonly class ServerSdkPayload
{
    /**
     * @param array<string, ConfigDefinition> $configs
     * @param array<string, Segment> $segments
     */
    public function __construct(
        public array $configs,
        public array $segments,
    ) {}

    /**
     * @return list<string>
     */
    public function configKeys(): array
    {
        return array_values(array_map(
            static fn(ConfigDefinition $definition): string => $definition->key,
            $this->configs,
        ));
    }

    /**
     * @return list<string>
     */
    public function configKeysAbsentFrom(self $newerPayload): array
    {
        return array_values(array_diff($this->configKeys(), $newerPayload->configKeys()));
    }
}
