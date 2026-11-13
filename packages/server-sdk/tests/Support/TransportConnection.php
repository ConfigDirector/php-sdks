<?php

declare(strict_types=1);

namespace ConfigDirector\Tests\Support;

use ConfigDirector\Internal\Connection\Connection;
use ConfigDirector\Internal\Connection\ServerSdkPayload;
use ConfigDirector\Internal\Polling\PollingTransport;

final readonly class TransportConnection implements Connection
{
    public function __construct(private PollingTransport $transport) {}

    #[\Override]
    public function payloadDeliveredWithin(int $timeoutMilliseconds): ?ServerSdkPayload
    {
        return $this->transport->storedPayloadForInitialization() === null ? null : new ServerSdkPayload([], []);
    }

    #[\Override]
    public function close(): void
    {
        $this->transport->close();
    }
}
