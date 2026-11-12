<?php

declare(strict_types=1);

namespace ConfigDirector\Tests\Internal\Evaluation;

use ConfigDirector\Internal\Evaluation\AttributeCondition;
use ConfigDirector\Internal\Evaluation\EvaluationContext;
use PHPUnit\Framework\TestCase;

final class AttributeConditionTest extends TestCase
{
    public function testAnIdentifierConditionComparesTheContextIdentifier(): void
    {
        $condition = self::textCondition('identifier', null, 'equals', 'u1');

        self::assertTrue($condition->holdsFor(new EvaluationContext(identifier: 'u1'), []));
    }

    public function testAnIdentifierConditionDoesNotHoldForAnotherIdentifier(): void
    {
        $condition = self::textCondition('identifier', null, 'equals', 'u1');

        self::assertFalse($condition->holdsFor(new EvaluationContext(identifier: 'u2'), []));
    }

    public function testANameConditionComparesTheContextName(): void
    {
        $condition = self::textCondition('name', null, 'equals', 'ann');

        self::assertTrue($condition->holdsFor(new EvaluationContext(name: 'ann'), []));
    }

    public function testAnAppNameConditionComparesTheApplicationName(): void
    {
        $condition = self::textCondition('appName', null, 'equals', 'shop');

        self::assertTrue($condition->holdsFor(new EvaluationContext(appName: 'shop'), []));
    }

    public function testAnAppVersionConditionComparesTheApplicationVersion(): void
    {
        $condition = new AttributeCondition('appVersion', null, '>=', 'semver', ['2.0.0']);

        self::assertTrue($condition->holdsFor(new EvaluationContext(appVersion: 'v2.3.4'), []));
    }

    public function testATraitsConditionReadsTheTraitAtItsPointer(): void
    {
        $condition = self::textCondition('traits', '/team/name', 'equals', 'core');

        self::assertTrue($condition->holdsFor(new EvaluationContext(traits: ['team' => ['name' => 'core']]), []));
    }

    public function testATraitsConditionWithoutAPointerComparesAsEmptyText(): void
    {
        $condition = self::textCondition('traits', null, 'equals', '');

        self::assertTrue($condition->holdsFor(new EvaluationContext(traits: ['plan' => 'pro']), []));
    }

    public function testATraitsConditionWithAnEmptyPointerComparesAsEmptyText(): void
    {
        $condition = self::textCondition('traits', '', 'equals', '');

        self::assertTrue($condition->holdsFor(new EvaluationContext(traits: ['' => 'pro']), []));
    }

    public function testAnAbsentTraitComparesAsEmptyTextSoANegatedOperatorHolds(): void
    {
        $condition = self::textCondition('traits', '/plan', 'is not one of', 'free');

        self::assertTrue($condition->holdsFor(new EvaluationContext(), []));
    }

    public function testANullTraitComparesAsEmptyText(): void
    {
        $condition = self::textCondition('traits', '/plan', 'equals', '');

        self::assertTrue($condition->holdsFor(new EvaluationContext(traits: ['plan' => null]), []));
    }

    public function testAnAbsentIdentifierComparesAsEmptyText(): void
    {
        $condition = self::textCondition('identifier', null, 'does not equal', 'u1');

        self::assertTrue($condition->holdsFor(new EvaluationContext(), []));
    }

    public function testAConditionOnAnUnknownAttributeNeverHolds(): void
    {
        $condition = self::textCondition('email', null, 'is not one of');

        self::assertFalse($condition->holdsFor(new EvaluationContext(), []));
    }

    public function testAConditionOfAnUnknownTargetTypeNeverHolds(): void
    {
        $condition = new AttributeCondition('identifier', null, 'equals', 'uuid', ['u1']);

        self::assertFalse($condition->holdsFor(new EvaluationContext(identifier: 'u1'), []));
    }

    public function testATextOperatorIsMatchedCaseInsensitively(): void
    {
        $condition = self::textCondition('identifier', null, 'Is NOT One Of', 'u2');

        self::assertTrue($condition->holdsFor(new EvaluationContext(identifier: 'u1'), []));
    }

    public function testADatetimeOperatorIsMatchedCaseInsensitively(): void
    {
        $condition = new AttributeCondition('traits', '/joined', 'Is Before', 'datetime', ['2026-01-28']);

        self::assertTrue($condition->holdsFor(new EvaluationContext(traits: ['joined' => '2025-12-31']), []));
    }

    public function testATrueTraitComparesAsTheTextTrue(): void
    {
        $condition = self::textCondition('traits', '/beta', 'equals', 'true');

        self::assertTrue($condition->holdsFor(new EvaluationContext(traits: ['beta' => true]), []));
    }

    public function testAFalseTraitComparesAsTheTextFalse(): void
    {
        $condition = self::textCondition('traits', '/beta', 'equals', 'false');

        self::assertTrue($condition->holdsFor(new EvaluationContext(traits: ['beta' => false]), []));
    }

    public function testAnIntegerTraitComparesAsItsDigitsInText(): void
    {
        $condition = self::textCondition('traits', '/seats', 'equals', '26');

        self::assertTrue($condition->holdsFor(new EvaluationContext(traits: ['seats' => 26]), []));
    }

    public function testAFractionalTraitComparesAsItsJsonTextInText(): void
    {
        $condition = self::textCondition('traits', '/ratio', 'equals', '26.5');

        self::assertTrue($condition->holdsFor(new EvaluationContext(traits: ['ratio' => 26.5]), []));
    }

    public function testANumericTraitComparesAsANumber(): void
    {
        $condition = new AttributeCondition('traits', '/seats', '>', 'number', ['10']);

        self::assertTrue($condition->holdsFor(new EvaluationContext(traits: ['seats' => 26]), []));
    }

    public function testAnAbsentTraitDoesNotEqualAnyNumber(): void
    {
        $condition = new AttributeCondition('traits', '/seats', '!=', 'number', ['10']);

        self::assertTrue($condition->holdsFor(new EvaluationContext(), []));
    }

    public function testAStructuredTraitComparesAsEmptyText(): void
    {
        $condition = self::textCondition('traits', '/tags', 'equals', '');

        self::assertTrue($condition->holdsFor(new EvaluationContext(traits: ['tags' => ['a']]), []));
    }

    public function testAnArrayTraitComparesItsElements(): void
    {
        $condition = new AttributeCondition('traits', '/tags', 'contains any of', 'array', ['blue']);

        self::assertTrue($condition->holdsFor(new EvaluationContext(traits: ['tags' => ['red', 'blue']]), []));
    }

    public function testADatetimeTraitComparesAsAnInstant(): void
    {
        $condition = new AttributeCondition('traits', '/joined', 'is before', 'datetime', ['2026-01-28']);

        self::assertTrue($condition->holdsFor(new EvaluationContext(traits: ['joined' => '2025-12-31']), []));
    }

    public function testAKindConditionComparesTheContextKind(): void
    {
        $condition = self::textCondition('kind', null, 'is one of', 'device', 'tenant');

        self::assertTrue($condition->holdsFor(new EvaluationContext(contextKind: 'device'), []));
    }

    public function testAKindConditionDoesNotHoldForAnotherKind(): void
    {
        $condition = self::textCondition('kind', null, 'is one of', 'device', 'tenant');

        self::assertFalse($condition->holdsFor(new EvaluationContext(contextKind: 'organization'), []));
    }

    public function testAnOmittedKindComparesAsUser(): void
    {
        $condition = self::textCondition('kind', null, 'equals', 'user');

        self::assertTrue($condition->holdsFor(new EvaluationContext(), []));
    }

    public function testAKindOtherThanUserDoesNotEqualUser(): void
    {
        $condition = self::textCondition('kind', null, 'does not equal', 'user');

        self::assertTrue($condition->holdsFor(new EvaluationContext(contextKind: 'tenant'), []));
    }

    public function testATraitNamedKindIsAnOrdinaryTrait(): void
    {
        $condition = self::textCondition('traits', '/kind', 'equals', 'robot');

        self::assertTrue($condition->holdsFor(new EvaluationContext(contextKind: 'device', traits: ['kind' => 'robot']), []));
    }

    public function testAKindConditionIgnoresATraitNamedKind(): void
    {
        $condition = self::textCondition('kind', null, 'equals', 'robot');

        self::assertFalse($condition->holdsFor(new EvaluationContext(contextKind: 'device', traits: ['kind' => 'robot']), []));
    }

    private static function textCondition(
        string $attribute,
        ?string $trait,
        string $operator,
        string ...$targetValues,
    ): AttributeCondition {
        return new AttributeCondition($attribute, $trait, $operator, 'text', array_values($targetValues));
    }
}
