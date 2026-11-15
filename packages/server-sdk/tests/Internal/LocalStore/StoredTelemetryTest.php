<?php

declare(strict_types=1);

namespace ConfigDirector\Tests\Internal\LocalStore;

use ConfigDirector\Internal\LocalStore\ExclusiveTelemetryLock;
use ConfigDirector\Internal\LocalStore\LocalStore;
use ConfigDirector\Internal\LocalStore\SharedTelemetryLock;
use ConfigDirector\Internal\LocalStore\StoredTelemetry;
use ConfigDirector\Tests\Support\RecordingLogger;
use ConfigDirector\Tests\Support\TemporaryFolder;
use PHPUnit\Framework\TestCase;

final class StoredTelemetryTest extends TestCase
{
    private string $storagePath;

    #[\Override]
    protected function setUp(): void
    {
        $this->storagePath = TemporaryFolder::create();
    }

    #[\Override]
    protected function tearDown(): void
    {
        TemporaryFolder::remove($this->storagePath);
    }

    public function testTelemetryTakenForAReportIsReadWholeAfterTheTelemetryThatFollowedWasTakenForTheNextReport(): void
    {
        $store = LocalStore::ofServerSdkKey('server-sdk-key', $this->storagePath, new RecordingLogger());
        $store->whileHoldingTheTelemetryLockShared(
            static fn(SharedTelemetryLock $telemetryLock): bool
                => $telemetryLock->addTelemetryOfAClient('{"marker":"added-before-the-first-report"}'),
        );
        $telemetryOfTheFirstReport = $store->whileHoldingTheTelemetryLockExclusive(
            static fn(ExclusiveTelemetryLock $telemetryLock): StoredTelemetry
                => $telemetryLock->takeStoredTelemetry(1_794_484_800_000),
        );
        $store->whileHoldingTheTelemetryLockShared(
            static fn(SharedTelemetryLock $telemetryLock): bool
                => $telemetryLock->addTelemetryOfAClient('{"marker":"added-after-the-first-report"}'),
        );

        $telemetryOfTheNextReport = $store->whileHoldingTheTelemetryLockExclusive(
            static fn(ExclusiveTelemetryLock $telemetryLock): StoredTelemetry
                => $telemetryLock->takeStoredTelemetry(1_794_484_830_000),
        );

        self::assertNotNull($telemetryOfTheFirstReport);
        self::assertNotNull($telemetryOfTheNextReport);
        self::assertSame(
            ['{"marker":"added-before-the-first-report"}'],
            iterator_to_array($telemetryOfTheFirstReport->telemetryFileLinesReadOnce()),
        );
        self::assertSame(
            ['{"marker":"added-after-the-first-report"}'],
            iterator_to_array($telemetryOfTheNextReport->telemetryFileLinesReadOnce()),
        );
    }
}
