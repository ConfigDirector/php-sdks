<?php

declare(strict_types=1);

namespace ConfigDirector\Tests\Internal\Value;

use ConfigDirector\EvaluationReason;
use ConfigDirector\Internal\Value\InCodeDefaultValueType;
use ConfigDirector\Internal\Value\TypedValue;
use PHPUnit\Framework\TestCase;

final class TypedValueTest extends TestCase
{
    public function testABooleanReadAcceptsTrue(): void
    {
        self::assertTrue(self::valueOf(self::booleanRead('true', 'boolean')));
    }

    public function testABooleanReadAcceptsFalse(): void
    {
        self::assertFalse(self::valueOf(self::booleanRead('false', 'boolean')));
    }

    public function testABooleanReadIgnoresLetterCase(): void
    {
        self::assertTrue(self::valueOf(self::booleanRead('TRUE', 'boolean')));
    }

    public function testABooleanReadAcceptsBooleanTextFromAStringConfig(): void
    {
        self::assertFalse(self::valueOf(self::booleanRead('False', 'string')));
    }

    public function testABooleanReadRefusesOne(): void
    {
        self::assertSame(EvaluationReason::InvalidBoolean, self::booleanRead('1', 'boolean'));
    }

    public function testABooleanReadRefusesYes(): void
    {
        self::assertSame(EvaluationReason::InvalidBoolean, self::booleanRead('yes', 'string'));
    }

    public function testABooleanReadRefusesTrueWithSurroundingSpaces(): void
    {
        self::assertSame(EvaluationReason::InvalidBoolean, self::booleanRead(' true ', 'boolean'));
    }

    public function testAStringReadReturnsTheTextOfAStringConfig(): void
    {
        self::assertSame('Monday', self::valueOf(self::stringRead('Monday', 'string')));
    }

    public function testAStringReadReturnsTheTextOfAnEnumConfig(): void
    {
        self::assertSame('large', self::valueOf(self::stringRead('large', 'enum')));
    }

    public function testAStringReadReturnsTheTextOfAUrlConfig(): void
    {
        self::assertSame('https://example.com/a', self::valueOf(self::stringRead('https://example.com/a', 'url')));
    }

    public function testAStringReadReturnsTheTextOfACustomConfig(): void
    {
        self::assertSame('a,b', self::valueOf(self::stringRead('a,b', 'custom')));
    }

    public function testAStringReadReturnsTheTextOfAJsonConfig(): void
    {
        self::assertSame('{"a":1}', self::valueOf(self::stringRead('{"a":1}', 'json')));
    }

    public function testAStringReadReturnsTheTextOfAConfigTypeThisVersionDoesNotKnow(): void
    {
        self::assertSame('P1D', self::valueOf(self::stringRead('P1D', 'duration')));
    }

    public function testAStringReadRefusesABooleanConfig(): void
    {
        self::assertSame(EvaluationReason::TypeMismatch, self::stringRead('true', 'boolean'));
    }

    public function testAStringReadRefusesAnIntegerConfig(): void
    {
        self::assertSame(EvaluationReason::TypeMismatch, self::stringRead('26', 'integer'));
    }

    public function testAStringReadRefusesAFloatConfig(): void
    {
        self::assertSame(EvaluationReason::TypeMismatch, self::stringRead('0.5', 'float'));
    }

    public function testAnIntegerReadAcceptsAWholeNumber(): void
    {
        self::assertSame(26, self::valueOf(self::integerRead('26', 'integer')));
    }

    public function testAnIntegerReadAcceptsANegativeNumber(): void
    {
        self::assertSame(-7, self::valueOf(self::integerRead('-7', 'integer')));
    }

    public function testAnIntegerReadAcceptsALeadingPlusSign(): void
    {
        self::assertSame(5, self::valueOf(self::integerRead('+5', 'integer')));
    }

    public function testAnIntegerReadAcceptsAWholeNumberWrittenWithADecimalPoint(): void
    {
        self::assertSame(26, self::valueOf(self::integerRead('26.0', 'float')));
    }

    public function testAnIntegerReadAcceptsAWholeNumberWrittenWithAnExponent(): void
    {
        self::assertSame(1000, self::valueOf(self::integerRead('1e3', 'float')));
    }

    public function testAnIntegerReadAcceptsTheLargestPhpInteger(): void
    {
        self::assertSame(9223372036854775807, self::valueOf(self::integerRead('9223372036854775807', 'integer')));
    }

    public function testAnIntegerReadAcceptsTheSmallestPhpInteger(): void
    {
        self::assertSame(PHP_INT_MIN, self::valueOf(self::integerRead('-9223372036854775808', 'integer')));
    }

    public function testAnIntegerReadRefusesAWholeNumberBelowThePhpIntegerRange(): void
    {
        self::assertSame(EvaluationReason::InvalidNumber, self::integerRead('-9223372036854775809', 'integer'));
    }

    public function testAnIntegerReadKeepsEveryDigitOfALargeWholeNumber(): void
    {
        self::assertSame(9007199254740993, self::valueOf(self::integerRead('9007199254740993', 'integer')));
    }

    public function testAnIntegerReadRefusesANumberWithAFraction(): void
    {
        self::assertSame(EvaluationReason::InvalidNumber, self::integerRead('26.5', 'float'));
    }

    public function testAnIntegerReadRefusesAWholeNumberBeyondThePhpIntegerRange(): void
    {
        self::assertSame(EvaluationReason::InvalidNumber, self::integerRead('9223372036854775808', 'integer'));
    }

    public function testAnIntegerReadRefusesAnExponentBeyondThePhpIntegerRange(): void
    {
        self::assertSame(EvaluationReason::InvalidNumber, self::integerRead('1e30', 'float'));
    }

    public function testAnIntegerReadRefusesWords(): void
    {
        self::assertSame(EvaluationReason::InvalidNumber, self::integerRead('Monday', 'string'));
    }

    public function testAnIntegerReadRefusesSurroundingSpaces(): void
    {
        self::assertSame(EvaluationReason::InvalidNumber, self::integerRead(' 26', 'string'));
    }

    public function testAnIntegerReadRefusesHexadecimalDigits(): void
    {
        self::assertSame(EvaluationReason::InvalidNumber, self::integerRead('0x1A', 'string'));
    }

    public function testAnIntegerReadRefusesDigitSeparators(): void
    {
        self::assertSame(EvaluationReason::InvalidNumber, self::integerRead('1_000', 'string'));
    }

    public function testAnIntegerReadRefusesASignWithoutDigits(): void
    {
        self::assertSame(EvaluationReason::InvalidNumber, self::integerRead('-', 'string'));
    }

    public function testAFloatReadAcceptsANumberWithAFraction(): void
    {
        self::assertSame(0.25, self::valueOf(self::floatRead('0.25', 'float')));
    }

    public function testAFloatReadReturnsAFloatForAWholeNumber(): void
    {
        self::assertSame(26.0, self::valueOf(self::floatRead('26', 'integer')));
    }

    public function testAFloatReadAcceptsAnExponent(): void
    {
        self::assertSame(0.0015, self::valueOf(self::floatRead('1.5e-3', 'float')));
    }

    public function testAFloatReadRefusesWords(): void
    {
        self::assertSame(EvaluationReason::InvalidNumber, self::floatRead('Monday', 'string'));
    }

    public function testAFloatReadRefusesInfinity(): void
    {
        self::assertSame(EvaluationReason::InvalidNumber, self::floatRead('Infinity', 'string'));
    }

    public function testAFloatReadRefusesANumberTooLargeForAFloat(): void
    {
        self::assertSame(EvaluationReason::InvalidNumber, self::floatRead('1e999', 'float'));
    }

    public function testAFloatReadRefusesSurroundingSpaces(): void
    {
        self::assertSame(EvaluationReason::InvalidNumber, self::floatRead('0.5 ', 'float'));
    }

    public function testAnArrayReadDecodesAJsonObject(): void
    {
        self::assertSame(
            ['greeting' => 'hello', 'nested' => ['on' => true]],
            self::valueOf(self::arrayRead('{"greeting":"hello","nested":{"on":true}}', 'json')),
        );
    }

    public function testAnArrayReadDecodesAJsonArray(): void
    {
        self::assertSame([1, 'two', 3.5], self::valueOf(self::arrayRead('[1,"two",3.5]', 'json')));
    }

    public function testAnArrayReadDecodesJsonHeldByAStringConfig(): void
    {
        self::assertSame(['a' => 1], self::valueOf(self::arrayRead('{"a":1}', 'string')));
    }

    public function testAnArrayReadRefusesMalformedJson(): void
    {
        self::assertSame(EvaluationReason::InvalidJson, self::arrayRead('{"greeting":', 'json'));
    }

    public function testAnArrayReadRefusesAJsonString(): void
    {
        self::assertSame(EvaluationReason::InvalidJson, self::arrayRead('"hello"', 'json'));
    }

    public function testAnArrayReadRefusesAJsonNumber(): void
    {
        self::assertSame(EvaluationReason::InvalidJson, self::arrayRead('5', 'json'));
    }

    public function testAnArrayReadRefusesAJsonBoolean(): void
    {
        self::assertSame(EvaluationReason::InvalidJson, self::arrayRead('true', 'json'));
    }

    public function testAnArrayReadRefusesJsonNull(): void
    {
        self::assertSame(EvaluationReason::InvalidJson, self::arrayRead('null', 'json'));
    }

    public function testAnEmptyValueIsMissingForABooleanRead(): void
    {
        self::assertSame(EvaluationReason::ValueMissing, self::booleanRead('', 'boolean'));
    }

    public function testAnAbsentValueIsMissingForAStringRead(): void
    {
        self::assertSame(EvaluationReason::ValueMissing, self::stringRead(null, 'string'));
    }

    public function testAnEmptyValueIsMissingForAnArrayRead(): void
    {
        self::assertSame(EvaluationReason::ValueMissing, self::arrayRead('', 'json'));
    }

    private static function booleanRead(?string $servedText, string $configType): TypedValue|EvaluationReason
    {
        return TypedValue::fromServedText($servedText, $configType, InCodeDefaultValueType::Boolean);
    }

    private static function stringRead(?string $servedText, string $configType): TypedValue|EvaluationReason
    {
        return TypedValue::fromServedText($servedText, $configType, InCodeDefaultValueType::String);
    }

    private static function integerRead(?string $servedText, string $configType): TypedValue|EvaluationReason
    {
        return TypedValue::fromServedText($servedText, $configType, InCodeDefaultValueType::Integer);
    }

    private static function floatRead(?string $servedText, string $configType): TypedValue|EvaluationReason
    {
        return TypedValue::fromServedText($servedText, $configType, InCodeDefaultValueType::Float);
    }

    private static function arrayRead(?string $servedText, string $configType): TypedValue|EvaluationReason
    {
        return TypedValue::fromServedText($servedText, $configType, InCodeDefaultValueType::Array);
    }

    /**
     * @return bool|string|int|float|array<array-key, mixed>
     */
    private static function valueOf(TypedValue|EvaluationReason $reading): bool|string|int|float|array
    {
        self::assertInstanceOf(TypedValue::class, $reading);

        return $reading->value;
    }
}
