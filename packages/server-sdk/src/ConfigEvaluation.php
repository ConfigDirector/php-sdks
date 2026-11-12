<?php

declare(strict_types=1);

namespace ConfigDirector;

/**
 * One evaluation of a config: the value it returned, why, and the context it was evaluated for.
 *
 * @template-covariant TValue of bool|string|int|float|array<array-key, mixed>
 */
final readonly class ConfigEvaluation
{
    /**
     * @param string $key The config key that was evaluated.
     * @param TValue $value The value returned, which is the in-code default value when
     *     `$isDefaultValue` is `true`.
     * @param string|null $valueId The ID ConfigDirector assigned to the value returned, when
     *     ConfigDirector sent one with it.
     * @param Variation|null $variation The variation that was served. It is `null` when the config
     *     has no variations or the in-code default value is returned.
     * @param bool $isDefaultValue Whether the evaluation returned the in-code default value.
     * @param EvaluationReason $reason Why the evaluation returned this value.
     * @param Context|null $context The context the config was evaluated for. It is `null` when no
     *     context was given or the context was ignored for an invalid kind.
     */
    public function __construct(
        public string $key,
        public bool|string|int|float|array $value,
        public ?string $valueId,
        public ?Variation $variation,
        public bool $isDefaultValue,
        public EvaluationReason $reason,
        public ?Context $context,
    ) {}
}
