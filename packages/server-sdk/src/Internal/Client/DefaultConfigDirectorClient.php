<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Client;

use ConfigDirector\ConfigDirectorClient;
use ConfigDirector\ConfigEvaluation;
use ConfigDirector\Context;
use ConfigDirector\EvaluationReason;
use ConfigDirector\Internal\Connection\ConfigDefinition;
use ConfigDirector\Internal\Connection\Connection;
use ConfigDirector\Internal\Connection\ServerSdkPayload;
use ConfigDirector\Internal\Connection\UndeliveredPayload;
use ConfigDirector\Internal\Evaluation\ConfigEvaluator;
use ConfigDirector\Internal\Evaluation\EvaluationContext;
use ConfigDirector\Internal\Evaluation\ServedBy;
use ConfigDirector\Internal\Evaluation\ServedValue;
use ConfigDirector\Internal\Value\InCodeDefaultValue;
use ConfigDirector\Internal\Value\TypedValue;
use ConfigDirector\Metadata;
use Psr\Log\LoggerInterface;

final class DefaultConfigDirectorClient implements ConfigDirectorClient
{
    private ?ServerSdkPayload $payload = null;

    private bool $disposed = false;

    private bool $isTakingInAPayload = false;

    /** @var list<ServerSdkPayload> */
    private array $payloadsArrivedDuringATakeIn = [];

    private readonly ConfigEvaluator $evaluator;

    private readonly InvalidContextKinds $invalidContextKinds;

    private readonly Watches $watches;

    public function __construct(
        private readonly Connection $connection,
        private readonly Metadata $metadata,
        private readonly int $timeoutMilliseconds,
        private readonly LoggerInterface $logger,
        private readonly EventHandlers $eventHandlers,
    ) {
        $this->evaluator = new ConfigEvaluator();
        $this->invalidContextKinds = new InvalidContextKinds();
        $this->watches = new Watches();
        ClientsDisposedAtShutdown::add($this);
    }

    public function __destruct()
    {
        $this->dispose();
    }

    #[\Override]
    public function initialize(): void
    {
        if ($this->disposed) {
            $this->logger->warning(
                '[ConfigDirectorClient] initialize() was called on a disposed client, which stays not ready. '
                . 'Create a new client instead.',
            );

            return;
        }

        $payload = $this->connection->payloadForInitialization();
        if ($payload instanceof UndeliveredPayload) {
            match ($payload) {
                UndeliveredPayload::WaitReachedTheTimeout => $this->logger->warning(
                    '[ConfigDirectorClient] Timed out waiting for initialization after {timeout}ms. '
                    . 'Configs return the in-code default value until values arrive.',
                    ['timeout' => $this->timeoutMilliseconds],
                ),
                UndeliveredPayload::FailedPollOnRecord => $this->logger->debug(
                    '[ConfigDirectorClient] initialize() did not wait for values because a failed poll is on '
                    . 'record. Configs return the in-code default value until values arrive.',
                ),
                UndeliveredPayload::ReasonLoggedAsAnError => null,
            };

            return;
        }

        $this->takeIn($payload);
        $this->logger->debug('[ConfigDirectorClient] Received initial payload from the server, client is ready');
    }

    #[\Override]
    public function isReady(): bool
    {
        $this->takeInThePayloadFoundByThisRead();

        return $this->payload !== null;
    }

    #[\Override]
    public function getValue(
        string $configKey,
        mixed $defaultValue,
        ?Context $context = null,
    ): bool|string|int|float|array {
        return $this->evaluate($configKey, $defaultValue, $context)->value;
    }

    #[\Override]
    public function evaluate(string $configKey, mixed $defaultValue, ?Context $context = null): ConfigEvaluation
    {
        $inCodeDefaultValue = InCodeDefaultValue::from($defaultValue);
        $this->takeInThePayloadFoundByThisRead();

        return $this->read($configKey, $inCodeDefaultValue, $context);
    }

    #[\Override]
    public function getAllConfigs(?Context $context = null, ?array $configKeys = null): array
    {
        $this->takeInThePayloadFoundByThisRead();
        $admittedContext = $this->admittedContextOrNone($context);
        if ($this->payload === null) {
            return [];
        }

        $states = [];
        foreach ($this->payload->configs as $definition) {
            if ($configKeys === null || in_array($definition->key, $configKeys, true)) {
                $states[$definition->key] = $definition->stateServing(
                    $this->valueServedTo($admittedContext, $definition, $this->payload),
                );
            }
        }

        return $states;
    }

    #[\Override]
    public function watch(
        string $configKey,
        mixed $defaultValue,
        callable $callback,
        ?Context $context = null,
    ): \Closure {
        $watch = new Watch($configKey, InCodeDefaultValue::from($defaultValue), $callback, $context);
        $this->watches->add($watch);

        return function () use ($watch): void {
            $this->watches->remove($watch);
        };
    }

    #[\Override]
    public function unwatch(string $configKey, ?callable $callback = null): void
    {
        if ($callback === null) {
            $this->watches->removeAllOn($configKey);
        } else {
            $this->watches->removeFirstWithCallback($configKey, $callback);
        }
    }

    #[\Override]
    public function unwatchAll(): void
    {
        $this->watches->removeAll();
    }

    #[\Override]
    public function on(string $eventName, callable $handler): void
    {
        $this->eventHandlers->add(ClientEventName::named($eventName), $handler);
    }

    #[\Override]
    public function off(string $eventName, ?callable $handler = null): void
    {
        $knownEventName = ClientEventName::named($eventName);
        if ($handler === null) {
            $this->eventHandlers->removeAllOf($knownEventName);
        } else {
            $this->eventHandlers->remove($knownEventName, $handler);
        }
    }

    #[\Override]
    public function dispose(): void
    {
        $isFirstDisposal = !$this->disposed;
        $this->disposed = true;
        if ($isFirstDisposal) {
            $this->connection->close();
        }

        $this->payload = null;
        $this->payloadsArrivedDuringATakeIn = [];
        $this->eventHandlers->removeAll();
        $this->watches->removeAll();
    }

    /**
     * @return ConfigEvaluation<bool|string|int|float|array<array-key, mixed>>
     */
    private function read(
        string $configKey,
        InCodeDefaultValue $inCodeDefaultValue,
        ?Context $context,
    ): ConfigEvaluation {
        $evaluation = $this->evaluationOf($configKey, $inCodeDefaultValue, $context);
        $this->eventHandlers->deliverConfigEvaluated($evaluation);

        return $evaluation;
    }

    /**
     * @return ConfigEvaluation<bool|string|int|float|array<array-key, mixed>>
     */
    private function evaluationOf(
        string $configKey,
        InCodeDefaultValue $inCodeDefaultValue,
        ?Context $context,
    ): ConfigEvaluation {
        $admittedContext = $this->admittedContextOrNone($context);
        $inCodeDefaultValueBecauseOf = static fn(EvaluationReason $reason): ConfigEvaluation => new ConfigEvaluation(
            $configKey,
            $inCodeDefaultValue->value,
            null,
            null,
            true,
            $reason,
            $admittedContext,
        );

        $definition = $this->payload->configs[$configKey] ?? null;
        if ($this->payload === null || $definition === null) {
            $this->logger->debug(
                "[ConfigDirectorClient] No config state found for '{configKey}', "
                . "returning the in-code default value '{value}'",
                ['configKey' => $configKey, 'value' => $inCodeDefaultValue->value],
            );

            return $inCodeDefaultValueBecauseOf(
                $this->payload === null ? EvaluationReason::ClientNotReady : EvaluationReason::ConfigStateMissing,
            );
        }

        $served = $this->valueServedTo($admittedContext, $definition, $this->payload);
        $state = $definition->stateServing($served);
        $typedValue = TypedValue::fromServedText($state->value, $state->type, $inCodeDefaultValue->type);
        $evaluation = $typedValue instanceof EvaluationReason
            ? $inCodeDefaultValueBecauseOf($typedValue)
            : new ConfigEvaluation(
                $configKey,
                $typedValue->value,
                $state->valueId,
                $state->variation,
                false,
                match ($served->servedBy) {
                    ServedBy::MatchingRule, ServedBy::DefaultTargetingRule => EvaluationReason::FoundMatch,
                    ServedBy::DefaultTargetingRuleAfterSkippedRollout => EvaluationReason::RolloutSkippedNoIdentifier,
                },
                $admittedContext,
            );
        $this->logger->debug(
            "[ConfigDirectorClient] Evaluated '{configKey}' to '{value}'",
            ['configKey' => $configKey, 'value' => $evaluation->value],
        );

        return $evaluation;
    }

    private function takeInThePayloadFoundByThisRead(): void
    {
        $payload = $this->disposed ? null : $this->connection->payloadFoundByARead();
        if ($payload !== null) {
            $this->takeIn($payload);
        }
    }

    public function takeIn(ServerSdkPayload $payload): void
    {
        if ($this->isTakingInAPayload) {
            $this->payloadsArrivedDuringATakeIn[] = $payload;

            return;
        }

        $this->isTakingInAPayload = true;
        try {
            $this->replaceTheHeldPayloadWith($payload);
            while ($this->payloadsArrivedDuringATakeIn !== []) {
                $this->replaceTheHeldPayloadWith(array_shift($this->payloadsArrivedDuringATakeIn));
            }
        } finally {
            $this->isTakingInAPayload = false;
        }
    }

    private function replaceTheHeldPayloadWith(ServerSdkPayload $payload): void
    {
        $update = $payload->configsUpdatedSince($this->payload);
        $becomesReady = $this->payload === null;
        $this->payload = $payload;
        if (!$becomesReady && $update->keys === [] && $update->removedKeys === []) {
            return;
        }

        $this->eventHandlers->deliverConfigsUpdated($update);
        $this->deliverToTheWatchesOn([...$update->keys, ...$update->removedKeys]);
        if ($becomesReady) {
            $this->eventHandlers->deliverClientReady();
        }
    }

    /**
     * @param list<string> $configKeys
     */
    private function deliverToTheWatchesOn(array $configKeys): void
    {
        foreach ($this->watches->on($configKeys) as $watch) {
            $value = $this->read($watch->configKey, $watch->inCodeDefaultValue, $watch->context)->value;
            try {
                $watch->receive($value);
            } catch (\Throwable $exception) {
                $this->logger->error(
                    "[ConfigDirectorClient] A watch on '{configKey}' threw an exception, which was ignored.",
                    ['configKey' => $watch->configKey, 'exception' => $exception],
                );
            }
        }
    }

    private function admittedContextOrNone(?Context $context): ?Context
    {
        if ($context === null || $context->kind === null || ContextKind::isValid($context->kind)) {
            return $context;
        }

        $kindSample = ContextKind::sampleOf($context->kind);
        match ($this->invalidContextKinds->record($kindSample)) {
            InvalidContextKindRecording::NewKindSample => $this->logger->error(
                "[ConfigDirectorClient] Ignored a context with the invalid kind '{kindSample}' and evaluated as if "
                . 'no context was supplied. A context kind has 1 to 64 characters, using only lowercase letters, '
                . "digits, '-' and '_'.",
                ['kindSample' => $kindSample],
            ),
            InvalidContextKindRecording::FirstBeyondSampleLimit => $this->logger->error(
                '[ConfigDirectorClient] Ignored a context with another invalid kind and evaluated as if no context '
                . 'was supplied. {sampleLimit} different invalid kinds were logged recently, so further invalid '
                . 'kinds are not logged individually.',
                ['sampleLimit' => InvalidContextKinds::DISTINCT_KIND_SAMPLE_LIMIT],
            ),
            InvalidContextKindRecording::KnownKindSample, InvalidContextKindRecording::BeyondSampleLimit => null,
        };

        return null;
    }

    private function valueServedTo(
        ?Context $context,
        ConfigDefinition $definition,
        ServerSdkPayload $payload,
    ): ServedValue {
        return $this->evaluator->evaluate(
            $definition->id,
            $definition->targetingRules,
            new EvaluationContext(
                $context?->id,
                $context?->kind,
                $context?->name,
                $context->traits ?? [],
                $this->metadata->appName,
                $this->metadata->appVersion,
            ),
            $payload->segments,
        );
    }
}
