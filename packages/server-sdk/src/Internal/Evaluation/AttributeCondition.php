<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Evaluation;

final readonly class AttributeCondition implements Condition
{
    /**
     * @param list<string> $targetValues
     */
    public function __construct(
        private string $attribute,
        private ?string $trait,
        private string $operator,
        private string $targetType,
        private array $targetValues,
    ) {}

    #[\Override]
    public function holdsFor(EvaluationContext $context, array $segments): bool
    {
        return match ($this->attribute) {
            'identifier' => $this->valueMatches($context->identifier),
            'kind' => $this->valueMatches($context->contextKind),
            'name' => $this->valueMatches($context->name),
            'appName' => $this->valueMatches($context->appName),
            'appVersion' => $this->valueMatches($context->appVersion),
            'traits' => $this->valueMatches($this->traitOf($context)),
            default => false,
        };
    }

    private function traitOf(EvaluationContext $context): mixed
    {
        return $this->trait === null ? null : TraitPointer::valueAt($this->trait, $context->traits);
    }

    private function valueMatches(mixed $value): bool
    {
        $operator = strtolower($this->operator);
        $text = ScalarText::of($value) ?? '';

        return match ($this->targetType) {
            'text' => TextComparison::matches($text, $operator, $this->targetValues),
            'number' => NumberComparison::matches($value, $operator, $this->targetValues),
            'semver' => SemverComparison::matches($text, $operator, $this->targetValues),
            'datetime' => DatetimeComparison::matches($text, $operator, $this->targetValues),
            'array' => ArrayComparison::matches($value, $operator, $this->targetValues),
            default => false,
        };
    }
}
