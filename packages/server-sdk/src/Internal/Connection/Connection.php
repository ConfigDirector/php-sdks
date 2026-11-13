<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Connection;

interface Connection
{
    public function payloadForInitialization(): ServerSdkPayload|UndeliveredPayload;

    public function payloadFoundByARead(): ?ServerSdkPayload;

    public function close(): void;
}
