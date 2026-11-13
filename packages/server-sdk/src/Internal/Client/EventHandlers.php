<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Client;

use ConfigDirector\ConfigEvaluatedEvent;
use ConfigDirector\ConfigEvaluation;
use ConfigDirector\ConfigsUpdatedEvent;
use Psr\Log\LoggerInterface;

final class EventHandlers
{
    /** @var array<string, list<callable>> */
    private array $handlersByEventName = [];

    public function __construct(private readonly LoggerInterface $logger) {}

    public function add(ClientEventName $eventName, callable $handler): void
    {
        $this->handlersByEventName[$eventName->value][] = $handler;
    }

    public function remove(ClientEventName $eventName, callable $handler): void
    {
        $handlers = $this->handlersByEventName[$eventName->value] ?? [];
        $position = array_search($handler, $handlers, true);
        if ($position !== false) {
            unset($handlers[$position]);
            $this->handlersByEventName[$eventName->value] = array_values($handlers);
        }
    }

    public function removeAllOf(ClientEventName $eventName): void
    {
        unset($this->handlersByEventName[$eventName->value]);
    }

    public function removeAll(): void
    {
        $this->handlersByEventName = [];
    }

    public function deliverClientReady(): void
    {
        $this->deliver(ClientEventName::ClientReady, []);
    }

    public function deliverConfigsUpdated(ConfigsUpdatedEvent $update): void
    {
        $this->deliver(ClientEventName::ConfigsUpdated, [$update]);
    }

    /**
     * @param ConfigEvaluation<bool|string|int|float|array<array-key, mixed>> $evaluation
     */
    public function deliverConfigEvaluated(ConfigEvaluation $evaluation): void
    {
        $this->deliver(ClientEventName::ConfigEvaluated, [new ConfigEvaluatedEvent($evaluation)]);
    }

    /**
     * @param list<object> $arguments
     */
    private function deliver(ClientEventName $eventName, array $arguments): void
    {
        foreach ($this->handlersByEventName[$eventName->value] ?? [] as $handler) {
            try {
                $handler(...$arguments);
            } catch (\Throwable $exception) {
                $this->logger->error(
                    "[ConfigDirectorClient] A handler for the event '{eventName}' threw an exception, "
                    . 'which was ignored.',
                    ['eventName' => $eventName->value, 'exception' => $exception],
                );
            }
        }
    }
}
