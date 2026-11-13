<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Telemetry;

use ConfigDirector\ConfigEvaluation;
use ConfigDirector\ConfigState;
use ConfigDirector\Internal\Value\InCodeDefaultValue;
use ConfigDirector\Internal\Value\InCodeDefaultValueType;

final class ReportedEvaluation
{
    private const int JSON_FLAGS = JSON_THROW_ON_ERROR
        | JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_INVALID_UTF8_SUBSTITUTE;

    private function __construct() {}

    /**
     * @param ConfigEvaluation<bool|string|int|float|array<array-key, mixed>> $evaluation
     */
    public static function eventJson(
        ConfigEvaluation $evaluation,
        InCodeDefaultValue $inCodeDefaultValue,
        ?ConfigState $stateServed,
        ?ReportedContext $reportedContext,
    ): string {
        $stateWhoseValueWasReturned = $evaluation->isDefaultValue ? null : $stateServed;
        $configType = $stateServed?->type;

        return json_encode(
            array_filter(
                [
                    'contextId' => $reportedContext?->identifier,
                    'contextKind' => $reportedContext?->kind,
                    'key' => $evaluation->key,
                    'type' => $configType,
                    'defaultValue' => ReportedValue::of($inCodeDefaultValue->value, $configType, null),
                    'requestedType' => match ($inCodeDefaultValue->type) {
                        InCodeDefaultValueType::Boolean => 'bool',
                        InCodeDefaultValueType::String => 'string',
                        InCodeDefaultValueType::Integer => 'int',
                        InCodeDefaultValueType::Float => 'float',
                        InCodeDefaultValueType::Array => 'array',
                    },
                    'evaluatedValue' => ReportedValue::of($evaluation->value, $configType, $evaluation->valueId),
                    'evaluatedValueId' => $evaluation->valueId,
                    'usedDefault' => $evaluation->isDefaultValue,
                    'evaluationReason' => $evaluation->reason->value,
                    'ruleId' => $stateWhoseValueWasReturned?->ruleId,
                    'shareId' => $stateWhoseValueWasReturned?->shareId,
                    'variationId' => $evaluation->variation?->id,
                ],
                static fn(bool|string|array|null $member): bool => $member !== null,
            ),
            self::JSON_FLAGS,
        );
    }
}
