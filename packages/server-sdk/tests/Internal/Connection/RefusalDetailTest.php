<?php

declare(strict_types=1);

namespace ConfigDirector\Tests\Internal\Connection;

use ConfigDirector\Internal\Connection\RefusalDetail;
use PHPUnit\Framework\TestCase;

final class RefusalDetailTest extends TestCase
{
    public function testAKeyWithSlashesRepeatedAsJsonTextWithEscapedSlashesIsLeftOut(): void
    {
        $detail = RefusalDetail::in('{"serverSdkKey":"team\/checkout\/5f2b9c41"}', 'team/checkout/5f2b9c41');

        self::assertStringNotContainsString('5f2b9c41', $detail);
    }

    public function testAKeyReadFromAFileWithItsLineBreakIsLeftOutWhenRepeatedAsJsonText(): void
    {
        $detail = RefusalDetail::in('{"serverSdkKey":"5f2b9c41d7e8a306\n"}', "5f2b9c41d7e8a306\n");

        self::assertStringNotContainsString('5f2b9c41d7e8a306', $detail);
    }

    public function testAKeyReadFromAFileWithItsLineBreakIsLeftOutWhenRepeatedWithoutTheLineBreak(): void
    {
        $detail = RefusalDetail::in("Unknown server SDK key '5f2b9c41d7e8a306'", "5f2b9c41d7e8a306\n");

        self::assertStringNotContainsString('5f2b9c41d7e8a306', $detail);
    }
}
