<?php

declare(strict_types=1);

namespace ConfigDirector\Tests\Internal\ServerSentEvents;

use ConfigDirector\Internal\ServerSentEvents\EventStreamLimitExceeded;
use ConfigDirector\Internal\ServerSentEvents\EventStreamParser;
use ConfigDirector\Internal\ServerSentEvents\ServerSentEvent;
use PHPUnit\Framework\TestCase;

final class EventStreamParserTest extends TestCase
{
    private const int ONE_MIB = 1_048_576;

    private const int SIXTEEN_MIB = 16_777_216;

    public function testTheDataLinesOfAnEventAreJoinedWithNewlines(): void
    {
        $events = [];
        $parser = self::parserCollectingInto($events);

        $parser->feed("data: first line\ndata: second line\ndata: third line\n\n");

        self::assertEquals([new ServerSentEvent('', "first line\nsecond line\nthird line")], $events);
    }

    public function testTheEventFieldNamesTheEvent(): void
    {
        $events = [];
        $parser = self::parserCollectingInto($events);

        $parser->feed("event: payload\ndata: {}\n\n");

        self::assertEquals([new ServerSentEvent('payload', '{}')], $events);
    }

    public function testTheNameOfAnEventDoesNotCarryOverToTheNextEvent(): void
    {
        $events = [];
        $parser = self::parserCollectingInto($events);

        $parser->feed("event: payload\ndata: first\n\ndata: second\n\n");

        self::assertEquals([new ServerSentEvent('payload', 'first'), new ServerSentEvent('', 'second')], $events);
    }

    public function testAByteOrderMarkStartingTheStreamIsSkipped(): void
    {
        $events = [];
        $parser = self::parserCollectingInto($events);

        $parser->feed("\xEF\xBB\xBFdata: first\n\n");

        self::assertEquals([new ServerSentEvent('', 'first')], $events);
    }

    public function testAByteOrderMarkStartingALaterLineIsPartOfItsFieldName(): void
    {
        $events = [];
        $parser = self::parserCollectingInto($events);

        $parser->feed("data: first\n\n\xEF\xBB\xBFdata: second\n\n");

        self::assertEquals([new ServerSentEvent('', 'first')], $events);
    }

    public function testOnlyOneSpaceAfterTheColonIsLeftOutOfTheValue(): void
    {
        $events = [];
        $parser = self::parserCollectingInto($events);

        $parser->feed("data:  indented\n\n");

        self::assertEquals([new ServerSentEvent('', ' indented')], $events);
    }

    public function testAValueWithoutASpaceAfterTheColonIsReadWhole(): void
    {
        $events = [];
        $parser = self::parserCollectingInto($events);

        $parser->feed("data:unspaced\n\n");

        self::assertEquals([new ServerSentEvent('', 'unspaced')], $events);
    }

    public function testAnEventWithoutDataIsNotDispatched(): void
    {
        $events = [];
        $parser = self::parserCollectingInto($events);

        $parser->feed("event: payload\nid: 42\n\n");

        self::assertSame([], $events);
    }

    public function testTheLastEventIdIsTheOneThePreviousConnectionReachedUntilTheServerSendsAnId(): void
    {
        $parser = new EventStreamParser('41', static function (ServerSentEvent $event): void {});

        $parser->feed("data: first\n\n");

        self::assertSame('41', $parser->lastEventId());
    }

    public function testTheIdFieldSetsTheLastEventId(): void
    {
        $parser = self::parserDiscardingEvents();

        $parser->feed("id: 41\ndata: first\n\nid: 42\ndata: second\n\n");

        self::assertSame('42', $parser->lastEventId());
    }

    public function testTheLastEventIdStaysAfterAnEventWithoutAnId(): void
    {
        $parser = self::parserDiscardingEvents();

        $parser->feed("id: 42\ndata: first\n\ndata: second\n\n");

        self::assertSame('42', $parser->lastEventId());
    }

    public function testAnIdContainingANulIsIgnored(): void
    {
        $parser = self::parserDiscardingEvents();

        $parser->feed("id: 42\ndata: first\n\nid: 4\x003\ndata: second\n\n");

        self::assertSame('42', $parser->lastEventId());
    }

    public function testTheRetryIsUnknownUntilTheServerSendsOne(): void
    {
        $parser = self::parserDiscardingEvents();

        $parser->feed("data: first\n\n");

        self::assertNull($parser->retryMilliseconds());
    }

    public function testANumericRetrySetsTheRetryInMilliseconds(): void
    {
        $parser = self::parserDiscardingEvents();

        $parser->feed("retry: 2500\n");

        self::assertSame(2_500, $parser->retryMilliseconds());
    }

    public function testARetryWithAUnitIsIgnored(): void
    {
        $parser = self::parserDiscardingEvents();

        $parser->feed("retry: 2500\nretry: 3s\n");

        self::assertSame(2_500, $parser->retryMilliseconds());
    }

    public function testANegativeRetryIsIgnored(): void
    {
        $parser = self::parserDiscardingEvents();

        $parser->feed("retry: 2500\nretry: -1\n");

        self::assertSame(2_500, $parser->retryMilliseconds());
    }

    public function testARetryTooLargeForAnIntegerIsIgnored(): void
    {
        $parser = self::parserDiscardingEvents();

        $parser->feed("retry: 2500\nretry: 9223372036854775808\n");

        self::assertSame(2_500, $parser->retryMilliseconds());
    }

    public function testTheKeepaliveCommentOfTheSdkServerDispatchesNoEvent(): void
    {
        $events = [];
        $parser = self::parserCollectingInto($events);

        $parser->feed(":\n\n: keepalive\n\n");

        self::assertSame([], $events);
    }

    public function testACommentBetweenTheDataLinesOfAnEventLeavesItsDataWhole(): void
    {
        $events = [];
        $parser = self::parserCollectingInto($events);

        $parser->feed("data: first line\n:\n: keepalive\ndata: second line\n\n");

        self::assertEquals([new ServerSentEvent('', "first line\nsecond line")], $events);
    }

    public function testACarriageReturnFollowedByALineFeedEndsALine(): void
    {
        $events = [];
        $parser = self::parserCollectingInto($events);

        $parser->feed("data: first line\r\ndata: second line\r\n\r\n");

        self::assertEquals([new ServerSentEvent('', "first line\nsecond line")], $events);
    }

    public function testACarriageReturnAloneEndsALine(): void
    {
        $events = [];
        $parser = self::parserCollectingInto($events);

        $parser->feed("data: first line\rdata: second line\r\r");

        self::assertEquals([new ServerSentEvent('', "first line\nsecond line")], $events);
    }

    public function testACarriageReturnAndALineFeedSplitAcrossTwoChunksEndOneLine(): void
    {
        $events = [];
        $parser = self::parserCollectingInto($events);

        $parser->feed("data: first line\r");
        $parser->feed("\ndata: second line\r\n\r\n");

        self::assertEquals([new ServerSentEvent('', "first line\nsecond line")], $events);
    }

    public function testAnEmptyChunkBetweenACarriageReturnAndALineFeedLeavesThemOneLineEnding(): void
    {
        $events = [];
        $parser = self::parserCollectingInto($events);

        $parser->feed("data: first line\r");
        $parser->feed('');
        $parser->feed("\ndata: second line\r\n\r\n");

        self::assertEquals([new ServerSentEvent('', "first line\nsecond line")], $events);
    }

    public function testAnEventSplitAcrossThreeChunksIsDispatchedWhole(): void
    {
        $events = [];
        $parser = self::parserCollectingInto($events);

        $parser->feed("event: payload\ndata: {\"kind\": \"fu");
        $parser->feed('ll", "con');
        $parser->feed("figs\": {}}\n\n");

        self::assertEquals([new ServerSentEvent('payload', '{"kind": "full", "configs": {}}')], $events);
    }

    public function testAStreamCutMidEventDispatchesNoPartialEvent(): void
    {
        $events = [];
        $parser = self::parserCollectingInto($events);

        $parser->feed("data: complete\n\ndata: cut off\n");

        self::assertEquals([new ServerSentEvent('', 'complete')], $events);
    }

    public function testALineOfOneMibPlusOneByteEndsTheStream(): void
    {
        $parser = self::parserDiscardingEvents();

        $this->expectException(EventStreamLimitExceeded::class);

        $parser->feed(self::dataLineOfBytes(self::ONE_MIB + 1) . "\n\n");
    }

    public function testALineOfExactlyOneMibIsRead(): void
    {
        $events = [];
        $parser = self::parserCollectingInto($events);

        $parser->feed(self::dataLineOfBytes(self::ONE_MIB) . "\n\n");

        self::assertCount(1, $events);
        self::assertSame(1_048_570, strlen($events[0]->data));
    }

    public function testALineThatNeverEndsEndsTheStreamOnceItHoldsMoreThanOneMib(): void
    {
        $parser = self::parserDiscardingEvents();
        $parser->feed(str_repeat('x', self::ONE_MIB));

        $this->expectException(EventStreamLimitExceeded::class);

        $parser->feed('x');
    }

    public function testAnEventFinishedBeforeALimitIsExceededInTheSameChunkIsDispatched(): void
    {
        $events = [];
        $parser = self::parserCollectingInto($events);

        $limitExceeded = null;
        try {
            $parser->feed("data: complete\n\n" . self::dataLineOfBytes(self::ONE_MIB + 1) . "\n\n");
        } catch (EventStreamLimitExceeded $exception) {
            $limitExceeded = $exception;
        }

        self::assertInstanceOf(EventStreamLimitExceeded::class, $limitExceeded);
        self::assertEquals([new ServerSentEvent('', 'complete')], $events);
    }

    public function testAnEventOfSixteenMibPlusOneByteEndsTheStream(): void
    {
        $parser = self::parserDiscardingEvents();

        $this->expectException(EventStreamLimitExceeded::class);

        $parser->feed(self::dataLinesHoldingBytes(self::SIXTEEN_MIB + 1) . "\n");
    }

    public function testAnEventOfExactlySixteenMibIsRead(): void
    {
        $events = [];
        $parser = self::parserCollectingInto($events);

        $parser->feed(self::dataLinesHoldingBytes(self::SIXTEEN_MIB) . "\n");

        self::assertCount(1, $events);
        self::assertSame(self::SIXTEEN_MIB, strlen($events[0]->data));
    }

    private static function dataLinesHoldingBytes(int $dataBytes): string
    {
        $longestValueOfALine = self::ONE_MIB - strlen('data: ');
        $lines = [];
        $bytesLeft = $dataBytes;
        while ($bytesLeft > $longestValueOfALine) {
            $lines[] = 'data: ' . str_repeat('x', $longestValueOfALine);
            $bytesLeft -= $longestValueOfALine + strlen("\n");
        }
        $lines[] = 'data: ' . str_repeat('x', $bytesLeft);

        return implode("\n", $lines) . "\n";
    }

    private static function dataLineOfBytes(int $lineBytes): string
    {
        return 'data: ' . str_repeat('x', $lineBytes - strlen('data: '));
    }

    private static function parserDiscardingEvents(): EventStreamParser
    {
        return new EventStreamParser('', static function (ServerSentEvent $event): void {});
    }

    /**
     * @param list<ServerSentEvent> $events
     */
    private static function parserCollectingInto(array &$events): EventStreamParser
    {
        return new EventStreamParser('', static function (ServerSentEvent $event) use (&$events): void {
            $events[] = $event;
        });
    }
}
