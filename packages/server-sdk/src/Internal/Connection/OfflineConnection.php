<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Connection;

final class OfflineConnection implements Connection
{
    #[\Override]
    public function payloadForInitialization(): ServerSdkPayload
    {
        return new ServerSdkPayload([], []);
    }

    #[\Override]
    public function payloadFoundByARead(): null
    {
        return null;
    }

    #[\Override]
    public function close(): void {}
}
