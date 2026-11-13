<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Connection;

use ConfigDirector\Internal\LocalStore\StoredPayload;
use ConfigDirector\Internal\Polling\PollingTransport;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

final class LocalStoreConnection implements Connection
{
    private ?PollingTransport $transport = null;

    private ?string $stampOfTheWriteLastRead = null;

    private ?ServerSdkPayload $payloadLastRead = null;

    private ?string $stampOfTheWriteLastDelivered = null;

    /**
     * @param \Closure(\Closure(StoredPayload): void $reportPayloadReceivedByAPoll): PollingTransport $openTransport
     */
    public function __construct(
        private readonly \Closure $openTransport,
        private readonly LoggerInterface $logger,
    ) {}

    #[\Override]
    public function payloadForInitialization(): ServerSdkPayload|UndeliveredPayload
    {
        $this->transport ??= ($this->openTransport)(function (StoredPayload $payloadReceived): void {
            $this->payloadReadFrom($payloadReceived, LogLevel::WARNING);
        });
        $storedPayload = $this->transport->storedPayloadForInitialization();

        return $storedPayload instanceof StoredPayload ? $this->payloadDeliveredFrom($storedPayload) : $storedPayload;
    }

    #[\Override]
    public function payloadFoundByARead(): ?ServerSdkPayload
    {
        $storedPayload = $this->transport?->storedPayloadFoundByAReadInALongRunningProcess();
        if ($storedPayload === null || $storedPayload->stampOfItsWrite() === $this->stampOfTheWriteLastDelivered) {
            return null;
        }

        return $this->payloadDeliveredFrom($storedPayload);
    }

    #[\Override]
    public function close(): void
    {
        $this->transport?->close();
    }

    private function payloadDeliveredFrom(StoredPayload $storedPayload): ServerSdkPayload
    {
        $this->stampOfTheWriteLastDelivered = $storedPayload->stampOfItsWrite();

        return $this->payloadReadFrom($storedPayload, LogLevel::DEBUG);
    }

    private function payloadReadFrom(
        StoredPayload $storedPayload,
        string $logLevelOfUnreadableDefinitions,
    ): ServerSdkPayload {
        if ($this->payloadLastRead !== null && $storedPayload->stampOfItsWrite() === $this->stampOfTheWriteLastRead) {
            return $this->payloadLastRead;
        }

        $payload = ServerSdkPayloadParser::payloadIn($storedPayload->payload);
        $payload->logUnreadableDefinitions($this->logger, $logLevelOfUnreadableDefinitions);
        $this->stampOfTheWriteLastRead = $storedPayload->stampOfItsWrite();
        $this->payloadLastRead = $payload;

        return $payload;
    }
}
