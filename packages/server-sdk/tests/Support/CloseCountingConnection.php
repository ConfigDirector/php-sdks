<?php

declare(strict_types=1);

namespace ConfigDirector\Tests\Support;

use ConfigDirector\Internal\Connection\Connection;
use ConfigDirector\Internal\Connection\ServerSdkPayload;

final class CloseCountingConnection implements Connection
{
    public int $timesClosed = 0;

    /**
     * @param (\Closure(): void)|null $whileClosing
     */
    public function __construct(
        private readonly ServerSdkPayload $payload,
        private readonly ?\Closure $whileClosing = null,
    ) {}

    #[\Override]
    public function payloadForInitialization(): ServerSdkPayload
    {
        return $this->payload;
    }

    #[\Override]
    public function payloadFoundByARead(): null
    {
        return null;
    }

    #[\Override]
    public function close(): void
    {
        $this->timesClosed++;
        ($this->whileClosing ?? static function (): void {})();
    }
}
