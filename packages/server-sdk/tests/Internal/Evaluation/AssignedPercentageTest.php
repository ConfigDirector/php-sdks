<?php

declare(strict_types=1);

namespace ConfigDirector\Tests\Internal\Evaluation;

use ConfigDirector\Internal\Evaluation\AssignedPercentage;
use PHPUnit\Framework\TestCase;

final class AssignedPercentageTest extends TestCase
{
    private const string CONTRACT_CONFIG_ID = '11111111-1111-4111-8111-111111111111';

    public function testTheAssignedPercentageHashesTheIdentifierThenTheConfigId(): void
    {
        self::assertSame(61.8, AssignedPercentage::of('00000000-0000-0000-0000-000000000001', 'abc', 'user'));
    }

    public function testTheAssignedPercentageChangesWithTheConfigId(): void
    {
        self::assertSame(34.0, AssignedPercentage::of('00000000-0000-0000-0000-0000000003e8', 'abc', 'user'));
    }

    public function testTheAssignedPercentageOfANumericIdentifierIsTakenFromItsText(): void
    {
        self::assertSame(13.5, AssignedPercentage::of('00000000-0000-0000-0000-0000000007d0', '378368375', 'user'));
    }

    public function testTheAssignedPercentageOfTheNextIdentifierLandsElsewhere(): void
    {
        self::assertSame(66.0, AssignedPercentage::of('00000000-0000-0000-0000-0000000003e8', '378368376', 'user'));
    }

    public function testTheAssignedPercentageOfAUserIsTheContractUserVector(): void
    {
        self::assertSame(7.8, AssignedPercentage::of(self::CONTRACT_CONFIG_ID, 'user-1', 'user'));
    }

    public function testTheAssignedPercentageOfADeviceAppendsTheKindToTheUserInput(): void
    {
        self::assertSame(59.0, AssignedPercentage::of(self::CONTRACT_CONFIG_ID, 'user-1', 'device'));
    }

    public function testTheAssignedPercentageOfATenantLandsElsewhereThanADevice(): void
    {
        self::assertSame(8.5, AssignedPercentage::of(self::CONTRACT_CONFIG_ID, 'user-1', 'tenant'));
    }

    public function testTheAssignedPercentageOfAnotherDeviceIsTheContractDeviceVector(): void
    {
        self::assertSame(75.0, AssignedPercentage::of(self::CONTRACT_CONFIG_ID, 'abc', 'device'));
    }
}
