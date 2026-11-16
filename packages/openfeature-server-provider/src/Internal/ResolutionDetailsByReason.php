<?php

declare(strict_types=1);

namespace ConfigDirector\OpenFeature\Internal;

use ConfigDirector\ConfigEvaluation;
use ConfigDirector\EvaluationReason;
use OpenFeature\implementation\provider\ResolutionDetails;
use OpenFeature\implementation\provider\ResolutionError;
use OpenFeature\interfaces\provider\ErrorCode;
use OpenFeature\interfaces\provider\Reason;
use OpenFeature\interfaces\provider\ResolutionDetails as ResolutionDetailsInterface;

final class ResolutionDetailsByReason
{
    private function __construct() {}

    /**
     * @param ConfigEvaluation<bool|string|int|float|array<array-key, mixed>> $evaluation
     */
    public static function ofTheEvaluation(ConfigEvaluation $evaluation): ResolutionDetailsInterface
    {
        return match ($evaluation->reason) {
            EvaluationReason::FoundMatch => self::servedValueResolution($evaluation, Reason::TARGETING_MATCH),
            EvaluationReason::RolloutSkippedNoIdentifier => self::servedValueResolution($evaluation, Reason::DEFAULT),
            EvaluationReason::ValueMissing => self::inCodeDefaultValueResolution($evaluation),
            EvaluationReason::ConfigStateMissing => self::failedResolution(
                $evaluation,
                ErrorCode::FLAG_NOT_FOUND(),
                "No config with the key '{$evaluation->key}' was found.",
            ),
            EvaluationReason::ClientNotReady => self::failedResolution(
                $evaluation,
                ErrorCode::PROVIDER_NOT_READY(),
                'ConfigDirector has not delivered config state yet.',
            ),
            EvaluationReason::TypeMismatch => self::typeMismatchResolution($evaluation),
            EvaluationReason::InvalidNumber => self::typeMismatchResolution($evaluation),
            EvaluationReason::InvalidJson => self::typeMismatchResolution($evaluation),
            EvaluationReason::InvalidBoolean => self::typeMismatchResolution($evaluation),
        };
    }

    /**
     * @param ConfigEvaluation<bool|string|int|float|array<array-key, mixed>> $evaluation
     */
    private static function typeMismatchResolution(ConfigEvaluation $evaluation): ResolutionDetailsInterface
    {
        return self::failedResolution(
            $evaluation,
            ErrorCode::TYPE_MISMATCH(),
            "The value of '{$evaluation->key}' cannot be read as the requested type ({$evaluation->reason->value}).",
        );
    }

    /**
     * @param ConfigEvaluation<bool|string|int|float|array<array-key, mixed>> $evaluation
     */
    private static function servedValueResolution(ConfigEvaluation $evaluation, string $reason): ResolutionDetailsInterface
    {
        $details = new ResolutionDetails();
        $details->setValue($evaluation->value);
        $details->setReason($reason);
        $details->setVariant($evaluation->variation->name ?? $evaluation->valueId);

        return $details;
    }

    /**
     * @param ConfigEvaluation<bool|string|int|float|array<array-key, mixed>> $evaluation
     */
    private static function inCodeDefaultValueResolution(ConfigEvaluation $evaluation): ResolutionDetailsInterface
    {
        $details = new ResolutionDetails();
        $details->setValue($evaluation->value);
        $details->setReason(Reason::DEFAULT);

        return $details;
    }

    /**
     * @param ConfigEvaluation<bool|string|int|float|array<array-key, mixed>> $evaluation
     */
    private static function failedResolution(
        ConfigEvaluation $evaluation,
        ErrorCode $errorCode,
        string $errorMessage,
    ): ResolutionDetailsInterface {
        $details = new ResolutionDetails();
        $details->setValue($evaluation->value);
        $details->setReason(Reason::ERROR);
        $details->setError(new ResolutionError($errorCode, $errorMessage));

        return $details;
    }
}
