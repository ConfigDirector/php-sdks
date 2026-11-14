<?php

declare(strict_types=1);

namespace ConfigDirector\Tests\StaticAnalysis;

use ConfigDirector\ConfigDirectorClient;

use function PHPStan\Testing\assertType;

final class TypesOfReads
{
    public function getValueWithAFalseInCodeDefaultValueIsABool(ConfigDirectorClient $client): void
    {
        assertType('bool', $client->getValue('new-checkout', false));
    }

    public function getValueWithABoolVariableAsInCodeDefaultValueIsABool(
        ConfigDirectorClient $client,
        bool $inCodeDefaultValue,
    ): void {
        assertType('bool', $client->getValue('new-checkout', $inCodeDefaultValue));
    }

    public function getValueWithAStringInCodeDefaultValueIsAString(ConfigDirectorClient $client): void
    {
        assertType('string', $client->getValue('greeting', 'hello'));
    }

    public function getValueWithAnIntInCodeDefaultValueIsAnInt(ConfigDirectorClient $client): void
    {
        assertType('int', $client->getValue('max-items', 20));
    }

    public function getValueWithAnIntVariableAsInCodeDefaultValueIsAnInt(
        ConfigDirectorClient $client,
        int $inCodeDefaultValue,
    ): void {
        assertType('int', $client->getValue('max-items', $inCodeDefaultValue));
    }

    public function getValueWithAFloatInCodeDefaultValueIsAFloat(ConfigDirectorClient $client): void
    {
        assertType('float', $client->getValue('discount-rate', 0.15));
    }

    public function getValueWithAnEmptyArrayInCodeDefaultValueIsAnArray(ConfigDirectorClient $client): void
    {
        assertType('array<mixed>', $client->getValue('theme', []));
    }

    public function getValueWithAMapInCodeDefaultValueIsAnArray(ConfigDirectorClient $client): void
    {
        assertType('array<mixed>', $client->getValue('theme', ['color' => 'blue']));
    }

    public function evaluateWithAFalseInCodeDefaultValueIsAnEvaluationOfABool(ConfigDirectorClient $client): void
    {
        assertType('ConfigDirector\ConfigEvaluation<bool>', $client->evaluate('new-checkout', false));
    }

    public function theValueOfAnEvaluationWithAFalseInCodeDefaultValueIsABool(ConfigDirectorClient $client): void
    {
        assertType('bool', $client->evaluate('new-checkout', false)->value);
    }

    public function theValueOfAnEvaluationWithAStringInCodeDefaultValueIsAString(ConfigDirectorClient $client): void
    {
        assertType('string', $client->evaluate('greeting', 'hello')->value);
    }

    public function theValueOfAnEvaluationWithAnIntInCodeDefaultValueIsAnInt(ConfigDirectorClient $client): void
    {
        assertType('int', $client->evaluate('max-items', 20)->value);
    }

    public function theValueOfAnEvaluationWithAFloatInCodeDefaultValueIsAFloat(ConfigDirectorClient $client): void
    {
        assertType('float', $client->evaluate('discount-rate', 0.15)->value);
    }

    public function theValueOfAnEvaluationWithAnEmptyArrayInCodeDefaultValueIsAnArray(
        ConfigDirectorClient $client,
    ): void {
        assertType('array<mixed>', $client->evaluate('theme', [])->value);
    }

    public function aWatchWithAFalseInCodeDefaultValueCallsBackWithABool(ConfigDirectorClient $client): void
    {
        $client->watch('new-checkout', false, static function ($newCheckout): void {
            assertType('bool', $newCheckout);
        });
    }

    public function aWatchWithAStringInCodeDefaultValueCallsBackWithAString(ConfigDirectorClient $client): void
    {
        $client->watch('greeting', 'hello', static function ($greeting): void {
            assertType('string', $greeting);
        });
    }

    public function aWatchWithAnIntInCodeDefaultValueCallsBackWithAnInt(ConfigDirectorClient $client): void
    {
        $client->watch('max-items', 20, static function ($maxItems): void {
            assertType('int', $maxItems);
        });
    }

    public function aWatchWithAFloatInCodeDefaultValueCallsBackWithAFloat(ConfigDirectorClient $client): void
    {
        $client->watch('discount-rate', 0.15, static function ($discountRate): void {
            assertType('float', $discountRate);
        });
    }

    public function aWatchWithAnEmptyArrayInCodeDefaultValueCallsBackWithAnArray(ConfigDirectorClient $client): void
    {
        $client->watch('theme', [], static function ($theme): void {
            assertType('array<mixed>', $theme);
        });
    }

    public function aWatchWithAMapInCodeDefaultValueCallsBackWithAnArray(ConfigDirectorClient $client): void
    {
        $client->watch('theme', ['color' => 'blue'], static function ($theme): void {
            assertType('array<mixed>', $theme);
        });
    }
}
