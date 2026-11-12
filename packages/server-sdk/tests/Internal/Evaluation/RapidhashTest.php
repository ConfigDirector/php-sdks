<?php

declare(strict_types=1);

namespace ConfigDirector\Tests\Internal\Evaluation;

use ConfigDirector\Internal\Evaluation\Rapidhash;
use PHPUnit\Framework\TestCase;

final class RapidhashTest extends TestCase
{
    private const int SEED = 0x397832987;

    public function testRapidhashOfAnEmptyMessageMatchesTheReferenceImplementation(): void
    {
        self::assertSame('5377612543505373799', self::unsignedHashOf(''));
    }

    public function testRapidhashOfOneByteMatchesTheReferenceImplementation(): void
    {
        self::assertSame('7674800498429868151', self::unsignedHashOf('a'));
    }

    public function testRapidhashOfTwoBytesMatchesTheReferenceImplementation(): void
    {
        self::assertSame('12048270741005468339', self::unsignedHashOf('ab'));
    }

    public function testRapidhashOfThreeBytesMatchesTheReferenceImplementation(): void
    {
        self::assertSame('8205525400821834274', self::unsignedHashOf('abc'));
    }

    public function testRapidhashOfFourBytesMatchesTheReferenceImplementation(): void
    {
        self::assertSame('2559843570930408943', self::unsignedHashOf('abcd'));
    }

    public function testRapidhashOfFiveBytesMatchesTheReferenceImplementation(): void
    {
        self::assertSame('17182111687207956362', self::unsignedHashOf('abcde'));
    }

    public function testRapidhashOfSevenBytesMatchesTheReferenceImplementation(): void
    {
        self::assertSame('13135472276134024436', self::unsignedHashOf('abcdefg'));
    }

    public function testRapidhashOfEightBytesMatchesTheReferenceImplementation(): void
    {
        self::assertSame('10825195283420988801', self::unsignedHashOf('abcdefgh'));
    }

    public function testRapidhashOfNineBytesMatchesTheReferenceImplementation(): void
    {
        self::assertSame('9324307379318471710', self::unsignedHashOf('abcdefghi'));
    }

    public function testRapidhashOfSixteenBytesMatchesTheReferenceImplementation(): void
    {
        self::assertSame('8410398172536096822', self::unsignedHashOf('0123456789abcdef'));
    }

    public function testRapidhashOfSeventeenBytesMatchesTheReferenceImplementation(): void
    {
        self::assertSame('8975841632926530338', self::unsignedHashOf('0123456789abcdefg'));
    }

    public function testRapidhashOfABucketingSaltMatchesTheReferenceImplementation(): void
    {
        self::assertSame('7715065197445012089', self::unsignedHashOf('10-11111111-1111-4111-8111-111111111111'));
    }

    public function testRapidhashOfTheContractUserVectorMatchesTheContract(): void
    {
        self::assertSame(
            'e9679cfc0acbbabe',
            sprintf('%016x', Rapidhash::hash('user-1-11111111-1111-4111-8111-111111111111', self::SEED)),
        );
    }

    public function testRapidhashOfFortyEightBytesMatchesTheReferenceImplementation(): void
    {
        self::assertSame('1185046273860983588', self::unsignedHashOf(str_repeat('x', 48)));
    }

    public function testRapidhashOfOneFullBlockMatchesTheReferenceImplementation(): void
    {
        self::assertSame('5679430438346846087', self::unsignedHashOf(str_repeat('y', 112)));
    }

    public function testRapidhashOfOneBlockAndOneByteMatchesTheReferenceImplementation(): void
    {
        self::assertSame('14103938338420400619', self::unsignedHashOf(str_repeat('z', 113)));
    }

    public function testRapidhashOfTwoBlocksAndARemainderMatchesTheReferenceImplementation(): void
    {
        self::assertSame('15088383192705595115', self::unsignedHashOf(str_repeat('w', 240)));
    }

    public function testRapidhashOfMultiByteUtf8MatchesTheReferenceImplementation(): void
    {
        self::assertSame('7481766294562949397', self::unsignedHashOf('héllo wörld ✓'));
    }

    private static function unsignedHashOf(string $message): string
    {
        return sprintf('%u', Rapidhash::hash($message, self::SEED));
    }
}
