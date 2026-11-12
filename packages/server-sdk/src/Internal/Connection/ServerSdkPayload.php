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
}
