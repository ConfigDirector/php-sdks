<?php

declare(strict_types=1);

namespace ConfigDirector\OpenFeature\Tests\Support;

final class StackTraceArguments
{
    private function __construct() {}

    /**
     * @return list<string>
     */
    public static function holding(string $text, \Throwable $exception): array
    {
        $argumentsHoldingTheText = [];
        $argumentsOfEveryFrame = array_column($exception->getTrace(), 'args');
        array_walk_recursive(
            $argumentsOfEveryFrame,
            static function (mixed $argument) use ($text, &$argumentsHoldingTheText): void {
                if (is_string($argument) && str_contains($argument, $text)) {
                    $argumentsHoldingTheText[] = $argument;
                }
            },
        );

        return $argumentsHoldingTheText;
    }
}
