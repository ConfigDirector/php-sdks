<?php

declare(strict_types=1);

namespace ConfigDirector\OpenFeature\Tests\Support;

use OpenFeature\interfaces\flags\EvaluationDetails;

final class ErrorOfTheEvaluation
{
    private function __construct() {}

    public static function codeIn(EvaluationDetails $details): ?string
    {
        $error = $details->getError();

        return $error === null ? null : (string) $error->getResolutionErrorCode();
    }

    public static function messageIn(EvaluationDetails $details): string
    {
        return $details->getError()?->getResolutionErrorMessage() ?? '';
    }
}
