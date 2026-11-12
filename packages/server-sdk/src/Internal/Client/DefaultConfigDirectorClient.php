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

    private readonly ConfigEvaluator $evaluator;

    private readonly InvalidContextKinds $invalidContextKinds;

    public function __construct(
        private readonly Connection $connection,
        private readonly Metadata $metadata,
        private readonly int $timeoutMilliseconds,
        private readonly LoggerInterface $logger,
    ) {
        $this->evaluator = new ConfigEvaluator();
        $this->invalidContextKinds = new InvalidContextKinds();
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

        $this->payload = $this->connection->payloadDeliveredWithin($this->timeoutMilliseconds);
        if ($this->payload === null) {
            $this->logger->warning(
                '[ConfigDirectorClient] Timed out waiting for initialization after {timeout}ms. '
                . 'Configs return the in-code default value until values arrive.',
                ['timeout' => $this->timeoutMilliseconds],
            );

            return;
        }

        $this->logger->debug('[ConfigDirectorClient] Received initial payload from the server, client is ready');
    }

    #[\Override]
    public function isReady(): bool
    {
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

    #[\Override]
    public function getAllConfigs(?Context $context = null, ?array $configKeys = null): array
    {
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
    public function dispose(): void
    {
        $this->payload = null;
        $this->disposed = true;
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
