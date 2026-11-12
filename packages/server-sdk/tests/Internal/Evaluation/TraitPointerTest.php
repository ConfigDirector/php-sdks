<?php

declare(strict_types=1);

namespace ConfigDirector\Tests\Internal\Evaluation;

use ConfigDirector\Internal\Evaluation\TraitPointer;
use PHPUnit\Framework\TestCase;

final class TraitPointerTest extends TestCase
{
    public function testAPointerSelectsANestedMember(): void
    {
        self::assertSame('core', TraitPointer::valueAt('/team/name', ['team' => ['name' => 'core']]));
    }

    public function testAnEscapedSlashSelectsAMemberWithASlashInItsName(): void
    {
        self::assertSame(1, TraitPointer::valueAt('/a~1b', ['a/b' => 1, 'm~n' => 2]));
    }

    public function testAnEscapedTildeSelectsAMemberWithATildeInItsName(): void
    {
        self::assertSame(2, TraitPointer::valueAt('/m~0n', ['a/b' => 1, 'm~n' => 2]));
    }

    public function testAnEscapedSlashIsDecodedBeforeAnEscapedTilde(): void
    {
        self::assertSame('x', TraitPointer::valueAt('/~01', ['~1' => 'x', '/' => 'y']));
    }

    public function testASingleSlashSelectsTheMemberWithAnEmptyName(): void
    {
        self::assertSame(['' => 'x'], TraitPointer::valueAt('/', ['' => ['' => 'x']]));
    }

    public function testTwoSlashesSelectNestedMembersWithEmptyNames(): void
    {
        self::assertSame('x', TraitPointer::valueAt('//', ['' => ['' => 'x']]));
    }

    public function testAnInvalidEscapeSelectsNothing(): void
    {
        self::assertNull(TraitPointer::valueAt('/~2', ['~2' => 'x']));
    }

    public function testATrailingTildeSelectsNothing(): void
    {
        self::assertNull(TraitPointer::valueAt('/a~', ['a~' => 'x']));
    }

    public function testAPointerWithoutALeadingSlashSelectsNothing(): void
    {
        self::assertNull(TraitPointer::valueAt('foo', ['foo' => 'x']));
    }

    public function testAUriFragmentPointerSelectsNothing(): void
    {
        self::assertNull(TraitPointer::valueAt('#/foo', ['foo' => 'x']));
    }

    public function testAnEmptyPointerSelectsNothing(): void
    {
        self::assertNull(TraitPointer::valueAt('', ['foo' => 'x']));
    }

    public function testPercentEncodingIsNotDecoded(): void
    {
        self::assertSame('x', TraitPointer::valueAt('/%25', ['%25' => 'x', '%' => 'y']));
    }

    public function testAnIndexSelectsAnArrayElement(): void
    {
        self::assertSame('b', TraitPointer::valueAt('/list/1', ['list' => ['a', 'b']]));
    }

    public function testAnIndexWithALeadingZeroSelectsNothing(): void
    {
        self::assertNull(TraitPointer::valueAt('/list/01', ['list' => ['a', 'b']]));
    }

    public function testASignedIndexSelectsNothing(): void
    {
        self::assertNull(TraitPointer::valueAt('/list/+1', ['list' => ['a', 'b']]));
    }

    public function testTheEndOfArrayMarkerSelectsNothing(): void
    {
        self::assertNull(TraitPointer::valueAt('/list/-', ['list' => ['a', 'b']]));
    }

    public function testAnIndexPastTheEndSelectsNothing(): void
    {
        self::assertNull(TraitPointer::valueAt('/list/2', ['list' => ['a', 'b']]));
    }

    public function testAnIndexTooLargeForAnIntegerSelectsNothing(): void
    {
        self::assertNull(TraitPointer::valueAt('/list/99999999999999999999', ['list' => ['a', 'b']]));
    }

    public function testAnIndexOfNonAsciiDigitsSelectsNothing(): void
    {
        self::assertNull(TraitPointer::valueAt('/list/١', ['list' => ['a', 'b']]));
    }

    public function testAStringIsNotIndexedByCharacter(): void
    {
        self::assertNull(TraitPointer::valueAt('/s/0', ['s' => 'abc']));
    }

    public function testNothingIsSelectedBelowAMissingMember(): void
    {
        self::assertNull(TraitPointer::valueAt('/missing/name', ['team' => ['name' => 'core']]));
    }

    public function testNothingIsSelectedBelowANullMember(): void
    {
        self::assertNull(TraitPointer::valueAt('/team/name', ['team' => null]));
    }

    public function testAMemberNamedWithDigitsIsSelectedFromAnObject(): void
    {
        self::assertSame('x', TraitPointer::valueAt('/7', ['7' => 'x']));
    }

    public function testAMemberNamedWithALeadingZeroIsSelectedFromAnObject(): void
    {
        self::assertSame('x', TraitPointer::valueAt('/01', ['01' => 'x']));
    }
}
