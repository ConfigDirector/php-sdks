<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Evaluation;

final readonly class EvaluationContext
{
    public const string USER_CONTEXT_KIND = 'user';

    public string $contextKind;

    /**
     * @param array<array-key, mixed> $traits
     */
    public function __construct(
        public ?string $identifier = null,
        ?string $contextKind = null,
        public ?string $name = null,
        public array $traits = [],
        public ?string $appName = null,
        public ?string $appVersion = null,
    ) {
        $this->contextKind = $contextKind ?? self::USER_CONTEXT_KIND;
    }
}
