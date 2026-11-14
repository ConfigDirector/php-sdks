<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\ServerSentEvents;

final readonly class ServerSentEvent
{
    public function __construct(
        public string $type,
        public string $data,
    ) {}
}
