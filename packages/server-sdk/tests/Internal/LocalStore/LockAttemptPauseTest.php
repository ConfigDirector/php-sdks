<?php

declare(strict_types=1);

namespace ConfigDirector\Tests\Internal\LocalStore;

use ConfigDirector\Internal\LocalStore\LockAttemptPause;
use PHPUnit\Framework\TestCase;

final class LockAttemptPauseTest extends TestCase
{
    public function testThePauseAfterTheFirstAttemptIs125MicrosecondsWithoutARandomShare(): void
    {
        self::assertSame(125, LockAttemptPause::microsecondsAfterAttempt(1, randomFraction: 0.0));
    }

    public function testThePauseAfterTheFirstAttemptIs250MicrosecondsWithTheWholeRandomShare(): void
    {
        self::assertSame(250, LockAttemptPause::microsecondsAfterAttempt(1, randomFraction: 1.0));
    }

    public function testThePauseAfterTheSecondAttemptIsTwiceThatOfTheFirst(): void
    {
        self::assertSame(250, LockAttemptPause::microsecondsAfterAttempt(2, randomFraction: 0.0));
    }

    public function testThePauseAfterTheThirdAttemptIsHalfAMillisecondWithoutARandomShare(): void
    {
        self::assertSame(500, LockAttemptPause::microsecondsAfterAttempt(3, randomFraction: 0.0));
    }

    public function testThePauseAfterTheThirdAttemptIsOneMillisecondWithTheWholeRandomShare(): void
    {
        self::assertSame(1_000, LockAttemptPause::microsecondsAfterAttempt(3, randomFraction: 1.0));
    }

    public function testThePauseAfterTheThousandthAttemptIsNoLongerThanOneMillisecond(): void
    {
        self::assertSame(1_000, LockAttemptPause::microsecondsAfterAttempt(1_000, randomFraction: 1.0));
    }
}
