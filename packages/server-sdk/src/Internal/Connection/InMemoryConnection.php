<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Connection;

use ConfigDirector\ConfigDirectorConnectionException;
use ConfigDirector\ConfigDirectorValidationException;
use ConfigDirector\Internal\Value\UnservableValue;
use Psr\Log\LoggerInterface;

final class InMemoryConnection implements Connection
{
    private const int STATUS_OF_A_REFUSED_CONNECTION = 401;

    private InMemoryConnectionState $state = InMemoryConnectionState::AwaitingInitialization;

    private NextInitialization $nextInitialization = NextInitialization::ServesTheValues;

    /** @var \Closure(ServerSdkPayload): void */
    private \Closure $deliverPayload;

    /**
     * @param array<string, ConfigDefinition> $configs
     * @param \Closure(ConfigDirectorConnectionException): void $deliverConnectionError
     */
    private function __construct(
        private array $configs,
        private readonly int $timeoutMilliseconds,
        private readonly LoggerInterface $logger,
        private readonly \Closure $deliverConnectionError,
    ) {
        $this->deliverPayload = static function (ServerSdkPayload $payload): void {};
    }

    /**
     * @param array<array-key, mixed> $values
     * @param \Closure(ConfigDirectorConnectionException): void $deliverConnectionError
     */
    public static function servingTheValues(
        array $values,
        int $timeoutMilliseconds,
        LoggerInterface $logger,
        \Closure $deliverConnectionError,
    ): self {
        return new self(self::configsServing($values), $timeoutMilliseconds, $logger, $deliverConnectionError);
    }

    /**
     * @param \Closure(ServerSdkPayload): void $deliverPayload
     */
    public function deliverPayloadsTo(\Closure $deliverPayload): void
    {
        $this->deliverPayload = $deliverPayload;
    }

    public function setValue(string $configKey, mixed $value): void
    {
        $this->configs = array_replace($this->configs, self::configsServing([$configKey => $value]));
        $this->deliverTheStoredValuesIfConnected();
    }

    public function removeValue(string $configKey): void
    {
        unset($this->configs[$configKey]);
        $this->deliverTheStoredValuesIfConnected();
    }

    /**
     * @param array<array-key, mixed> $values
     */
    public function replaceValues(array $values): void
    {
        $this->configs = self::configsServing($values);
        $this->nextInitialization = NextInitialization::ServesTheValues;
        $this->deliverTheStoredValuesIfConnected();
    }

    public function holdInitialization(): void
    {
        $this->nextInitialization = NextInitialization::IsHeld;
    }

    public function completeInitialization(): void
    {
        match ($this->state) {
            InMemoryConnectionState::HoldingAnInitialization => $this->completeTheHeldInitialization(),
            InMemoryConnectionState::AwaitingInitialization,
            InMemoryConnectionState::Connected => $this->cancelAHoldOfTheNextInitialization(),
            InMemoryConnectionState::Closed => null,
        };
    }

    public function failInitialization(): void
    {
        match ($this->state) {
            InMemoryConnectionState::HoldingAnInitialization => $this->reportARefusedConnection(),
            InMemoryConnectionState::AwaitingInitialization,
            InMemoryConnectionState::Connected => $this->nextInitialization = NextInitialization::Fails,
            InMemoryConnectionState::Closed => null,
        };
    }

    #[\Override]
    public function payloadForInitialization(): ServerSdkPayload|UndeliveredPayload
    {
        $nextInitialization = $this->nextInitialization;
        $this->nextInitialization = NextInitialization::ServesTheValues;

        return match ($nextInitialization) {
            NextInitialization::ServesTheValues => $this->connectWithTheStoredValues(),
            NextInitialization::IsHeld => $this->holdUntilTheTimeout(),
            NextInitialization::Fails => $this->refuseTheInitialization(),
        };
    }

    #[\Override]
    public function payloadFoundByARead(): null
    {
        return null;
    }

    #[\Override]
    public function close(): void
    {
        $this->state = InMemoryConnectionState::Closed;
    }

    private function connectWithTheStoredValues(): ServerSdkPayload
    {
        $this->state = InMemoryConnectionState::Connected;

        return $this->payloadOfTheStoredValues();
    }

    private function holdUntilTheTimeout(): UndeliveredPayload
    {
        $this->state = InMemoryConnectionState::HoldingAnInitialization;
        usleep($this->timeoutMilliseconds * 1_000);

        return UndeliveredPayload::WaitReachedTheTimeout;
    }

    private function refuseTheInitialization(): UndeliveredPayload
    {
        $this->reportARefusedConnection();

        return UndeliveredPayload::ReasonLoggedAsAnError;
    }

    private function completeTheHeldInitialization(): void
    {
        $this->state = InMemoryConnectionState::Connected;
        $this->deliverTheStoredValuesIfConnected();
    }

    private function cancelAHoldOfTheNextInitialization(): void
    {
        $this->nextInitialization = match ($this->nextInitialization) {
            NextInitialization::IsHeld => NextInitialization::ServesTheValues,
            NextInitialization::ServesTheValues, NextInitialization::Fails => $this->nextInitialization,
        };
    }

    private function reportARefusedConnection(): void
    {
        $this->state = InMemoryConnectionState::AwaitingInitialization;
        $error = new ConfigDirectorConnectionException(
            sprintf(
                'Connection failed with status: %d (the test client failed this initialization). This is an '
                . 'unrecoverable error.',
                self::STATUS_OF_A_REFUSED_CONNECTION,
            ),
            self::STATUS_OF_A_REFUSED_CONNECTION,
        );
        $this->logger->error(
            '[InMemoryConnection] The connection encountered an unrecoverable error because the test client failed '
            . 'this initialization. Configs return the in-code default value until values arrive.',
            ['exception' => $error],
        );
        ($this->deliverConnectionError)($error);
    }

    private function deliverTheStoredValuesIfConnected(): void
    {
        if ($this->state === InMemoryConnectionState::Connected) {
            ($this->deliverPayload)($this->payloadOfTheStoredValues());
        }
    }

    private function payloadOfTheStoredValues(): ServerSdkPayload
    {
        return new ServerSdkPayload($this->configs, []);
    }

    /**
     * @param array<array-key, mixed> $values
     *
     * @return array<string, ConfigDefinition>
     */
    private static function configsServing(array $values): array
    {
        try {
            return ConfigDefinition::servingEachValueToEveryContext($values, 'test-config:');
        } catch (UnservableValue $unservable) {
            throw new ConfigDirectorValidationException($unservable->getMessage(), previous: $unservable);
        }
    }
}
