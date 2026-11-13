<?php

declare(strict_types=1);

namespace ConfigDirector\Tests\Internal\Telemetry;

use ConfigDirector\Internal\Telemetry\ValueId;
use PHPUnit\Framework\TestCase;

final class ValueIdTest extends TestCase
{
    public function testTheValueIdOfHelloIsTheOneEverySdkGives(): void
    {
        self::assertSame('1MoOW7eqAPjhZeoELVwO9G', ValueId::of('hello'));
    }

    public function testTheValueIdOfWorldIsTheOneEverySdkGives(): void
    {
        self::assertSame('2Cg0gndCS8p6nDE5aa6LcI', ValueId::of('world'));
    }

    public function testTheValueIdOfANumberWrittenAsTextIsTheOneEverySdkGives(): void
    {
        self::assertSame('3VWjGpOwynZPh07ivDC56c', ValueId::of('42'));
    }

    public function testTheValueIdOfTheEmptyTextIsTheOneEverySdkGives(): void
    {
        self::assertSame('6ve2WrOl3mnciB6WIL2fIa', ValueId::of(''));
    }

    public function testAValueIdIsPaddedWithZerosToTwentyTwoCharacters(): void
    {
        self::assertSame('00FwZBdJhz9Xmmqwie9qZ7', ValueId::of('value-301'));
    }

    public function testTheValueIdOfTextBeyondAsciiIsTheOneOfItsUtf8Bytes(): void
    {
        self::assertSame('72EPKD3gIfelX9DAJXAwHg', ValueId::of('unicode ☂ café'));
    }
}
