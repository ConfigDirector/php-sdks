<?php

declare(strict_types=1);

namespace ConfigDirector\Tests\Internal\Evaluation;

use ConfigDirector\Internal\Evaluation\AttributeCondition;
use ConfigDirector\Internal\Evaluation\EvaluationContext;
use ConfigDirector\Internal\Evaluation\Segment;
use ConfigDirector\Internal\Evaluation\SegmentCondition;
use PHPUnit\Framework\TestCase;

final class SegmentConditionTest extends TestCase
{
    private const string ACME_SEGMENT_ID = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    private const string MISSING_SEGMENT_ID = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';

    public function testInHoldsForAContextInTheSegment(): void
    {
        $condition = new SegmentCondition('in', self::ACME_SEGMENT_ID);

        self::assertTrue($condition->holdsFor(self::contextWithEmail('ann@acme.com'), self::acmeEmployees()));
    }

    public function testInDoesNotHoldForAContextOutsideTheSegment(): void
    {
        $condition = new SegmentCondition('in', self::ACME_SEGMENT_ID);

        self::assertFalse($condition->holdsFor(self::contextWithEmail('bob@example.com'), self::acmeEmployees()));
    }

    public function testNotInHoldsForAContextOutsideTheSegment(): void
    {
        $condition = new SegmentCondition('not in', self::ACME_SEGMENT_ID);

        self::assertTrue($condition->holdsFor(self::contextWithEmail('bob@example.com'), self::acmeEmployees()));
    }

    public function testNotInDoesNotHoldForAContextInTheSegment(): void
    {
        $condition = new SegmentCondition('not in', self::ACME_SEGMENT_ID);

        self::assertFalse($condition->holdsFor(self::contextWithEmail('ann@acme.com'), self::acmeEmployees()));
    }

    public function testSegmentOperatorsAreMatchedCaseInsensitively(): void
    {
        $condition = new SegmentCondition('Not In', self::ACME_SEGMENT_ID);

        self::assertTrue($condition->holdsFor(self::contextWithEmail('bob@example.com'), self::acmeEmployees()));
    }

    public function testAnUnknownSegmentOperatorNeverHolds(): void
    {
        $condition = new SegmentCondition('equals', self::ACME_SEGMENT_ID);

        self::assertFalse($condition->holdsFor(self::contextWithEmail('ann@acme.com'), self::acmeEmployees()));
    }

    public function testInAMissingSegmentDoesNotHold(): void
    {
        $condition = new SegmentCondition('in', self::MISSING_SEGMENT_ID);

        self::assertFalse($condition->holdsFor(self::contextWithEmail('ann@acme.com'), self::acmeEmployees()));
    }

    public function testNotInAMissingSegmentDoesNotHold(): void
    {
        $condition = new SegmentCondition('not in', self::MISSING_SEGMENT_ID);

        self::assertFalse($condition->holdsFor(self::contextWithEmail('ann@acme.com'), self::acmeEmployees()));
    }

    public function testNotInASegmentDoesNotHoldWhenThePayloadHasNoSegments(): void
    {
        $condition = new SegmentCondition('not in', self::ACME_SEGMENT_ID);

        self::assertFalse($condition->holdsFor(self::contextWithEmail('ann@acme.com'), []));
    }

    public function testAContextIsInASegmentWhenALaterGroupMatches(): void
    {
        $condition = new SegmentCondition('in', self::ACME_SEGMENT_ID);
        $segments = [self::ACME_SEGMENT_ID => new Segment([[self::planIs('pro')], [self::emailEndsWith('@acme.com')]])];

        self::assertTrue($condition->holdsFor(self::contextWithEmail('ann@acme.com'), $segments));
    }

    public function testAGroupMatchesWhenEveryConditionHolds(): void
    {
        $condition = new SegmentCondition('in', self::ACME_SEGMENT_ID);
        $segments = [self::ACME_SEGMENT_ID => new Segment([[self::planIs('pro'), self::emailEndsWith('@acme.com')]])];
        $context = new EvaluationContext(traits: ['plan' => 'pro', 'email' => 'ann@acme.com']);

        self::assertTrue($condition->holdsFor($context, $segments));
    }

    public function testAGroupDoesNotMatchWhenOneConditionFails(): void
    {
        $condition = new SegmentCondition('in', self::ACME_SEGMENT_ID);
        $segments = [self::ACME_SEGMENT_ID => new Segment([[self::planIs('pro'), self::emailEndsWith('@acme.com')]])];
        $context = new EvaluationContext(traits: ['plan' => 'pro', 'email' => 'ann@example.com']);

        self::assertFalse($condition->holdsFor($context, $segments));
    }

    public function testAnAbsentTraitComparesAsEmptyTextInsideAGroup(): void
    {
        $condition = new SegmentCondition('in', self::ACME_SEGMENT_ID);
        $notOnTheFreePlan = new AttributeCondition('traits', '/plan', 'is not one of', 'text', ['free']);
        $segments = [self::ACME_SEGMENT_ID => new Segment([[$notOnTheFreePlan]])];

        self::assertTrue($condition->holdsFor(new EvaluationContext(), $segments));
    }

    public function testAGroupWithNoConditionsMatchesEveryContext(): void
    {
        $condition = new SegmentCondition('in', self::ACME_SEGMENT_ID);
        $segments = [self::ACME_SEGMENT_ID => new Segment([[]])];

        self::assertTrue($condition->holdsFor(new EvaluationContext(), $segments));
    }

    public function testASegmentWithNoGroupsHoldsNoContext(): void
    {
        $condition = new SegmentCondition('in', self::ACME_SEGMENT_ID);
        $segments = [self::ACME_SEGMENT_ID => new Segment([])];

        self::assertFalse($condition->holdsFor(self::contextWithEmail('ann@acme.com'), $segments));
    }

    public function testEveryContextIsNotInASegmentWithNoGroups(): void
    {
        $condition = new SegmentCondition('not in', self::ACME_SEGMENT_ID);
        $segments = [self::ACME_SEGMENT_ID => new Segment([])];

        self::assertTrue($condition->holdsFor(self::contextWithEmail('ann@acme.com'), $segments));
    }

    /**
     * @return array<string, Segment>
     */
    private static function acmeEmployees(): array
    {
        return [self::ACME_SEGMENT_ID => new Segment([[self::emailEndsWith('@acme.com')]])];
    }

    private static function contextWithEmail(string $email): EvaluationContext
    {
        return new EvaluationContext(traits: ['email' => $email]);
    }

    private static function emailEndsWith(string $domain): AttributeCondition
    {
        return new AttributeCondition('traits', '/email', 'ends with any of', 'text', [$domain]);
    }

    private static function planIs(string $plan): AttributeCondition
    {
        return new AttributeCondition('traits', '/plan', 'equals', 'text', [$plan]);
    }
}
