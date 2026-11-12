<?php

declare(strict_types=1);

namespace ConfigDirector\OpenFeature\Tests;

use ConfigDirector\OpenFeature\Version;
use PHPUnit\Framework\TestCase;

final class VersionTest extends TestCase
{
    public function testTheVersionIsASemanticVersionWithoutAPrefix(): void
    {
        self::assertMatchesRegularExpression('/^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.-]+)?$/', Version::CURRENT);
    }
}
