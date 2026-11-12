<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Connection;

interface Connection
{
    public function payloadDeliveredWithin(int $timeoutMilliseconds): ?ServerSdkPayload;
}
