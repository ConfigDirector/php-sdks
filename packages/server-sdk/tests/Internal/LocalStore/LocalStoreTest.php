<?php

declare(strict_types=1);

namespace ConfigDirector\Tests\Internal\LocalStore;

use ConfigDirector\Internal\LocalStore\LocalStore;
use ConfigDirector\Internal\LocalStore\StoredPayload;
use ConfigDirector\Tests\Support\RecordingLogger;
use ConfigDirector\Tests\Support\TemporaryFolder;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

final class LocalStoreTest extends TestCase
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

    public function testANewStoreHoldsNoPayload(): void
    {
        $store = LocalStore::ofServerSdkKey('server-sdk-key', $this->storagePath, new RecordingLogger());

        self::assertNull($store->storedPayload());
    }

    public function testReadingANewStoreLogsNoError(): void
    {
        $logger = new RecordingLogger();
        $store = LocalStore::ofServerSdkKey('server-sdk-key', $this->storagePath, $logger);

        $store->storedPayload();

        self::assertSame([], $logger->contextsLoggedAt(LogLevel::ERROR));
    }

    public function testAPayloadStoredUnderTheRefreshLockIsReadBackByAnotherStoreObject(): void
    {
        $writingStore = LocalStore::ofServerSdkKey('server-sdk-key', $this->storagePath, new RecordingLogger());
        $refreshLock = $writingStore->refreshLockAcquiredWithin(0);
        self::assertNotNull($refreshLock);

        $refreshLock->replaceStoredPayload(new StoredPayload(
            [
                'payloadVersion' => 1,
                'kind' => 'full',
                'timestamp' => '2026-11-12T10:00:00.123Z',
                'configs' => [
                    'float-config' => [
                        'key' => 'float-config',
                        'target' => ['defaultValue' => '26.0', 'percentage' => 50.0, 'position' => 0],
                    ],
                    'greeting' => ['key' => 'greeting', 'target' => ['defaultValue' => 'héllo/wörld "quoted"']],
                ],
                'segments' => [],
            ],
            1_794_477_600_123,
            '0f8fad5b-d9cb-469f-a165-70867728950e',
        ));
        $refreshLock->release();

        $readingStore = LocalStore::ofServerSdkKey('server-sdk-key', $this->storagePath, new RecordingLogger());
        self::assertEquals(
            new StoredPayload(
                [
                    'payloadVersion' => 1,
                    'kind' => 'full',
                    'timestamp' => '2026-11-12T10:00:00.123Z',
                    'configs' => [
                        'float-config' => [
                            'key' => 'float-config',
                            'target' => ['defaultValue' => '26.0', 'percentage' => 50.0, 'position' => 0],
                        ],
                        'greeting' => ['key' => 'greeting', 'target' => ['defaultValue' => 'héllo/wörld "quoted"']],
                    ],
                    'segments' => [],
                ],
                1_794_477_600_123,
                '0f8fad5b-d9cb-469f-a165-70867728950e',
            ),
            $readingStore->storedPayload(),
        );
    }

    public function testAWholeNumberedFloatInThePayloadIsReadBackAsAFloat(): void
    {
        $store = LocalStore::ofServerSdkKey('server-sdk-key', $this->storagePath, new RecordingLogger());
        $refreshLock = $store->refreshLockAcquiredWithin(0);
        self::assertNotNull($refreshLock);

        $refreshLock->replaceStoredPayload(new StoredPayload(['percentage' => 100.0], 1, 'session-1'));

        self::assertSame(['percentage' => 100.0], $store->storedPayload()?->payload);
    }

    public function testAStoredPayloadReplacesTheOneStoredBefore(): void
    {
        $store = LocalStore::ofServerSdkKey('server-sdk-key', $this->storagePath, new RecordingLogger());
        $refreshLock = $store->refreshLockAcquiredWithin(0);
        self::assertNotNull($refreshLock);
        $refreshLock->replaceStoredPayload(new StoredPayload(['configs' => ['first' => []]], 1_000, 'session-1'));

        $refreshLock->replaceStoredPayload(new StoredPayload(['configs' => ['second' => []]], 2_000, 'session-1'));

        self::assertEquals(
            new StoredPayload(['configs' => ['second' => []]], 2_000, 'session-1'),
            $store->storedPayload(),
        );
    }

    public function testTheStoreIsAFolderNamedByTheSha256DigestOfTheServerSdkKeyInsideTheStoragePath(): void
    {
        $store = LocalStore::ofServerSdkKey('server-sdk-key', $this->storagePath, new RecordingLogger());
        $refreshLock = $store->refreshLockAcquiredWithin(0);
        self::assertNotNull($refreshLock);

        $refreshLock->replaceStoredPayload(new StoredPayload(['configs' => []], 1, 'session-1'));

        self::assertFileExists(
            $this->storagePath
            . '/add45d3fbae45285dcd74320ecebb931a054c563edc414cd120865f8ff4e09f8/payload.json',
        );
    }

    public function testTwoServerSdkKeysSharingAStoragePathKeepTheirOwnPayloads(): void
    {
        $firstStore = LocalStore::ofServerSdkKey('server-sdk-key', $this->storagePath, new RecordingLogger());
        $secondStore = LocalStore::ofServerSdkKey('another-server-sdk-key', $this->storagePath, new RecordingLogger());
        $firstRefreshLock = $firstStore->refreshLockAcquiredWithin(0);
        $secondRefreshLock = $secondStore->refreshLockAcquiredWithin(0);
        self::assertNotNull($firstRefreshLock);
        self::assertNotNull($secondRefreshLock);

        $firstRefreshLock->replaceStoredPayload(new StoredPayload(['environment' => 'first'], 1, 'session-1'));
        $secondRefreshLock->replaceStoredPayload(new StoredPayload(['environment' => 'second'], 2, 'session-2'));

        self::assertEquals(new StoredPayload(['environment' => 'first'], 1, 'session-1'), $firstStore->storedPayload());
        self::assertEquals(
            new StoredPayload(['environment' => 'second'], 2, 'session-2'),
            $secondStore->storedPayload(),
        );
    }

    public function testTheServerSdkKeyAppearsInNoFileNameAndNoFileContent(): void
    {
        $store = LocalStore::ofServerSdkKey('server-sdk-key-kept-off-disk', $this->storagePath, new RecordingLogger());
        $refreshLock = $store->refreshLockAcquiredWithin(0);
        self::assertNotNull($refreshLock);

        $refreshLock->replaceStoredPayload(new StoredPayload(['configs' => []], 1, 'session-1'));
        $refreshLock->release();

        $namesAndContents = '';
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->storagePath, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($files as $file) {
            self::assertInstanceOf(\SplFileInfo::class, $file);
            $namesAndContents .= $file->getPathname() . "\n";
            $namesAndContents .= $file->isFile() ? file_get_contents($file->getPathname()) . "\n" : '';
        }
        self::assertStringContainsString('payload.json', $namesAndContents);
        self::assertStringNotContainsString('server-sdk-key-kept-off-disk', $namesAndContents);
    }

    public function testAPayloadFileThatIsNotJsonIsReadAsAnEmptyStore(): void
    {
        $store = LocalStore::ofServerSdkKey('server-sdk-key', $this->storagePath, new RecordingLogger());
        file_put_contents($this->payloadFilePath(), 'not json');

        self::assertNull($store->storedPayload());
    }

    public function testAPayloadFileThatIsNotJsonLogsOneErrorNamingTheFileHoweverOftenItIsRead(): void
    {
        $logger = new RecordingLogger();
        $store = LocalStore::ofServerSdkKey('server-sdk-key', $this->storagePath, $logger);
        file_put_contents($this->payloadFilePath(), 'not json');

        $store->storedPayload();
        $store->storedPayload();

        self::assertSame([['file' => $this->payloadFilePath()]], $logger->contextsLoggedAt(LogLevel::ERROR));
    }

    public function testAPayloadFileCutShortIsReadAsAnEmptyStore(): void
    {
        $store = LocalStore::ofServerSdkKey('server-sdk-key', $this->storagePath, new RecordingLogger());
        file_put_contents(
            $this->payloadFilePath(),
            '{"confirmedFreshAt":1794477600123,"sessionId":"session-1","payload":{"configs":{"float-con',
        );

        self::assertNull($store->storedPayload());
    }

    public function testAPayloadFileWithoutASessionIdIsReadAsAnEmptyStore(): void
    {
        $store = LocalStore::ofServerSdkKey('server-sdk-key', $this->storagePath, new RecordingLogger());
        file_put_contents($this->payloadFilePath(), '{"confirmedFreshAt":1794477600123,"payload":{"configs":{}}}');

        self::assertNull($store->storedPayload());
    }

    public function testAPayloadFileWithoutTheTimeItWasConfirmedFreshIsReadAsAnEmptyStore(): void
    {
        $store = LocalStore::ofServerSdkKey('server-sdk-key', $this->storagePath, new RecordingLogger());
        file_put_contents($this->payloadFilePath(), '{"sessionId":"session-1","payload":{"configs":{}}}');

        self::assertNull($store->storedPayload());
    }

    public function testAPayloadFileWithoutAPayloadIsReadAsAnEmptyStore(): void
    {
        $store = LocalStore::ofServerSdkKey('server-sdk-key', $this->storagePath, new RecordingLogger());
        file_put_contents($this->payloadFilePath(), '{"confirmedFreshAt":1794477600123,"sessionId":"session-1"}');

        self::assertNull($store->storedPayload());
    }

    public function testAPayloadFileWhoseJsonIsNotAnObjectIsReadAsAnEmptyStore(): void
    {
        $store = LocalStore::ofServerSdkKey('server-sdk-key', $this->storagePath, new RecordingLogger());
        file_put_contents($this->payloadFilePath(), '1794477600123');

        self::assertNull($store->storedPayload());
    }

    public function testAPayloadFileWithAnEmptySessionIdIsReadAsAnEmptyStore(): void
    {
        $store = LocalStore::ofServerSdkKey('server-sdk-key', $this->storagePath, new RecordingLogger());
        file_put_contents(
            $this->payloadFilePath(),
            '{"confirmedFreshAt":1794477600123,"sessionId":"","payload":{"configs":{}}}',
        );

        self::assertNull($store->storedPayload());
    }

    public function testAPayloadFileWhoseTimeConfirmedFreshIsTextIsReadAsAnEmptyStore(): void
    {
        $store = LocalStore::ofServerSdkKey('server-sdk-key', $this->storagePath, new RecordingLogger());
        file_put_contents(
            $this->payloadFilePath(),
            '{"confirmedFreshAt":"2026-11-12T10:00:00Z","sessionId":"session-1","payload":{"configs":{}}}',
        );

        self::assertNull($store->storedPayload());
    }

    public function testAPayloadFileWhosePayloadIsTextIsReadAsAnEmptyStore(): void
    {
        $store = LocalStore::ofServerSdkKey('server-sdk-key', $this->storagePath, new RecordingLogger());
        file_put_contents(
            $this->payloadFilePath(),
            '{"confirmedFreshAt":1794477600123,"sessionId":"session-1","payload":"configs"}',
        );

        self::assertNull($store->storedPayload());
    }

    public function testADirectoryInPlaceOfThePayloadFileIsReadAsAnEmptyStoreWithOneError(): void
    {
        $logger = new RecordingLogger();
        $store = LocalStore::ofServerSdkKey('server-sdk-key', $this->storagePath, $logger);
        mkdir($this->payloadFilePath());

        $storedPayload = $store->storedPayload();

        self::assertNull($storedPayload);
        self::assertSame([['file' => $this->payloadFilePath()]], $logger->contextsLoggedAt(LogLevel::ERROR));
    }

    public function testFieldsOfThePayloadFileThatTheSdkDoesNotKnowAreIgnored(): void
    {
        $store = LocalStore::ofServerSdkKey('server-sdk-key', $this->storagePath, new RecordingLogger());
        file_put_contents(
            $this->payloadFilePath(),
            '{"confirmedFreshAt":1794477600123,"sessionId":"session-1","addedLater":true,"payload":{"configs":{}}}',
        );

        self::assertEquals(
            new StoredPayload(['configs' => []], 1_794_477_600_123, 'session-1'),
            $store->storedPayload(),
        );
    }

    public function testAPayloadFileLargerThan32MebibytesIsReadAsAnEmptyStore(): void
    {
        $store = LocalStore::ofServerSdkKey('server-sdk-key', $this->storagePath, new RecordingLogger());
        $payloadFile = fopen($this->payloadFilePath(), 'wb');
        self::assertIsResource($payloadFile);
        fwrite($payloadFile, '{"confirmedFreshAt":1794477600123,"sessionId":"session-1","payload":{"configs":{}}}');
        for ($mebibyte = 1; $mebibyte <= 32; $mebibyte++) {
            fwrite($payloadFile, str_repeat(' ', 1024 * 1024));
        }
        fclose($payloadFile);

        self::assertNull($store->storedPayload());
    }

    public function testAPayloadFileOfExactly32MebibytesIsRead(): void
    {
        $store = LocalStore::ofServerSdkKey('server-sdk-key', $this->storagePath, new RecordingLogger());
        $json = '{"confirmedFreshAt":1794477600123,"sessionId":"session-1","payload":{"configs":{}}}';
        $payloadFile = fopen($this->payloadFilePath(), 'wb');
        self::assertIsResource($payloadFile);
        fwrite($payloadFile, $json);
        for ($mebibyte = 1; $mebibyte <= 31; $mebibyte++) {
            fwrite($payloadFile, str_repeat(' ', 1024 * 1024));
        }
        fwrite($payloadFile, str_repeat(' ', 1024 * 1024 - strlen($json)));
        fclose($payloadFile);

        self::assertEquals(
            new StoredPayload(['configs' => []], 1_794_477_600_123, 'session-1'),
            $store->storedPayload(),
        );
    }

    public function testAPayloadThatCannotBeWrittenLeavesTheStoredPayloadAndLogsOneErrorNamingTheFile(): void
    {
        $logger = new RecordingLogger();
        $store = LocalStore::ofServerSdkKey('server-sdk-key', $this->storagePath, $logger);
        $refreshLock = $store->refreshLockAcquiredWithin(0);
        self::assertNotNull($refreshLock);
        $refreshLock->replaceStoredPayload(new StoredPayload(['configs' => ['first' => []]], 1_000, 'session-1'));
        mkdir($this->payloadFilePath() . '.tmp');

        $isReplaced = $refreshLock->replaceStoredPayload(
            new StoredPayload(['configs' => ['second' => []]], 2_000, 'session-1'),
        );

        self::assertFalse($isReplaced);
        self::assertEquals(
            new StoredPayload(['configs' => ['first' => []]], 1_000, 'session-1'),
            $store->storedPayload(),
        );
        self::assertSame([['file' => $this->payloadFilePath()]], $logger->contextsLoggedAt(LogLevel::ERROR));
    }

    public function testAPayloadThatCannotBeWrittenAsJsonLeavesTheStoredPayloadAndLogsOneErrorNamingTheFile(): void
    {
        $logger = new RecordingLogger();
        $store = LocalStore::ofServerSdkKey('server-sdk-key', $this->storagePath, $logger);
        $refreshLock = $store->refreshLockAcquiredWithin(0);
        self::assertNotNull($refreshLock);
        $refreshLock->replaceStoredPayload(new StoredPayload(['configs' => ['first' => []]], 1_000, 'session-1'));

        $isReplaced = $refreshLock->replaceStoredPayload(
            new StoredPayload(['configs' => ['second' => "not UTF-8: \xB1\x31"]], 2_000, 'session-1'),
        );

        self::assertFalse($isReplaced);
        self::assertEquals(
            new StoredPayload(['configs' => ['first' => []]], 1_000, 'session-1'),
            $store->storedPayload(),
        );
        self::assertSame([['file' => $this->payloadFilePath()]], $logger->contextsLoggedAt(LogLevel::ERROR));
    }

    public function testASecondRefreshLockIsNotAcquiredWhileTheFirstIsHeld(): void
    {
        $store = LocalStore::ofServerSdkKey('server-sdk-key', $this->storagePath, new RecordingLogger());
        $heldRefreshLock = $store->refreshLockAcquiredWithin(0);
        self::assertNotNull($heldRefreshLock);

        $secondRefreshLock = $store->refreshLockAcquiredWithin(0);

        self::assertNull($secondRefreshLock);
    }

    public function testAcquiringTheRefreshLockWaitsForAHeldLockUpToTheTimeout(): void
    {
        $store = LocalStore::ofServerSdkKey('server-sdk-key', $this->storagePath, new RecordingLogger());
        $heldRefreshLock = $store->refreshLockAcquiredWithin(0);
        self::assertNotNull($heldRefreshLock);

        $startedAt = hrtime(true);
        $secondRefreshLock = $store->refreshLockAcquiredWithin(200);
        $waitedMilliseconds = (hrtime(true) - $startedAt) / 1_000_000;

        self::assertNull($secondRefreshLock);
        self::assertGreaterThanOrEqual(200, $waitedMilliseconds);
        self::assertLessThan(1_000, $waitedMilliseconds);
    }

    public function testAReleasedRefreshLockCanBeAcquiredAgain(): void
    {
        $store = LocalStore::ofServerSdkKey('server-sdk-key', $this->storagePath, new RecordingLogger());
        $firstRefreshLock = $store->refreshLockAcquiredWithin(0);
        self::assertNotNull($firstRefreshLock);

        $firstRefreshLock->release();

        self::assertNotNull($store->refreshLockAcquiredWithin(0));
    }

    public function testARefreshLockThatIsNoLongerReferencedCanBeAcquiredAgain(): void
    {
        $store = LocalStore::ofServerSdkKey('server-sdk-key', $this->storagePath, new RecordingLogger());
        $firstRefreshLock = $store->refreshLockAcquiredWithin(0);
        self::assertNotNull($firstRefreshLock);

        unset($firstRefreshLock);

        self::assertNotNull($store->refreshLockAcquiredWithin(0));
    }

    public function testAReleasedRefreshLockRefusesToReplaceTheStoredPayload(): void
    {
        $store = LocalStore::ofServerSdkKey('server-sdk-key', $this->storagePath, new RecordingLogger());
        $refreshLock = $store->refreshLockAcquiredWithin(0);
        self::assertNotNull($refreshLock);
        $refreshLock->release();

        $this->expectException(\LogicException::class);

        $refreshLock->replaceStoredPayload(new StoredPayload(['configs' => []], 1, 'session-1'));
    }

    public function testAnEmptyStoreIsFilledWithTheFetchedPayload(): void
    {
        $store = LocalStore::ofServerSdkKey('server-sdk-key', $this->storagePath, new RecordingLogger());

        $filledPayload = $store->storedPayloadFilledWithin(
            1_000,
            static fn(): StoredPayload => new StoredPayload(['configs' => ['fetched' => []]], 1_000, 'session-1'),
        );

        self::assertEquals(new StoredPayload(['configs' => ['fetched' => []]], 1_000, 'session-1'), $filledPayload);
        self::assertEquals(
            new StoredPayload(['configs' => ['fetched' => []]], 1_000, 'session-1'),
            LocalStore::ofServerSdkKey('server-sdk-key', $this->storagePath, new RecordingLogger())->storedPayload(),
        );
    }

    public function testTheFetchOfAnEmptyStoreIsGivenTheWholeTimeoutWhenNothingHoldsTheRefreshLock(): void
    {
        $store = LocalStore::ofServerSdkKey('server-sdk-key', $this->storagePath, new RecordingLogger());
        $remainingMillisecondsGiven = null;

        $store->storedPayloadFilledWithin(
            3_000,
            static function (int $remainingMilliseconds) use (&$remainingMillisecondsGiven): ?StoredPayload {
                $remainingMillisecondsGiven = $remainingMilliseconds;

                return null;
            },
        );

        self::assertGreaterThan(2_500, $remainingMillisecondsGiven);
        self::assertLessThanOrEqual(3_000, $remainingMillisecondsGiven);
    }

    public function testAStoreThatHoldsAPayloadIsNotFetchedAgain(): void
    {
        $store = LocalStore::ofServerSdkKey('server-sdk-key', $this->storagePath, new RecordingLogger());
        $refreshLock = $store->refreshLockAcquiredWithin(0);
        self::assertNotNull($refreshLock);
        $refreshLock->replaceStoredPayload(new StoredPayload(['configs' => ['stored' => []]], 1_000, 'session-1'));
        $refreshLock->release();
        $fetches = 0;

        $filledPayload = $store->storedPayloadFilledWithin(
            1_000,
            static function () use (&$fetches): StoredPayload {
                $fetches++;

                return new StoredPayload(['configs' => ['fetched' => []]], 2_000, 'session-2');
            },
        );

        self::assertSame(0, $fetches);
        self::assertEquals(new StoredPayload(['configs' => ['stored' => []]], 1_000, 'session-1'), $filledPayload);
    }

    public function testAStoreThatHoldsAPayloadIsReadWithoutWaitingForTheRefreshLock(): void
    {
        $store = LocalStore::ofServerSdkKey('server-sdk-key', $this->storagePath, new RecordingLogger());
        $heldRefreshLock = $store->refreshLockAcquiredWithin(0);
        self::assertNotNull($heldRefreshLock);
        $heldRefreshLock->replaceStoredPayload(new StoredPayload(['configs' => ['stored' => []]], 1_000, 'session-1'));

        $startedAt = hrtime(true);
        $filledPayload = $store->storedPayloadFilledWithin(
            2_000,
            static fn(): StoredPayload => new StoredPayload(['configs' => ['fetched' => []]], 2_000, 'session-2'),
        );
        $waitedMilliseconds = (hrtime(true) - $startedAt) / 1_000_000;

        self::assertEquals(new StoredPayload(['configs' => ['stored' => []]], 1_000, 'session-1'), $filledPayload);
        self::assertLessThan(1_000, $waitedMilliseconds);
    }

    public function testAFetchThatBringsNothingLeavesTheStoreEmpty(): void
    {
        $store = LocalStore::ofServerSdkKey('server-sdk-key', $this->storagePath, new RecordingLogger());

        $filledPayload = $store->storedPayloadFilledWithin(1_000, static fn(): ?StoredPayload => null);

        self::assertNull($filledPayload);
        self::assertNull($store->storedPayload());
    }

    public function testAnEmptyStoreIsNotFetchedWhileAnotherHolderKeepsTheRefreshLockPastTheTimeout(): void
    {
        $store = LocalStore::ofServerSdkKey('server-sdk-key', $this->storagePath, new RecordingLogger());
        $heldRefreshLock = $store->refreshLockAcquiredWithin(0);
        self::assertNotNull($heldRefreshLock);
        $fetches = 0;

        $filledPayload = $store->storedPayloadFilledWithin(
            100,
            static function () use (&$fetches): StoredPayload {
                $fetches++;

                return new StoredPayload(['configs' => ['fetched' => []]], 1_000, 'session-1');
            },
        );

        self::assertSame(0, $fetches);
        self::assertNull($filledPayload);
    }

    public function testFillingTheStoreReleasesTheRefreshLock(): void
    {
        $store = LocalStore::ofServerSdkKey('server-sdk-key', $this->storagePath, new RecordingLogger());

        $store->storedPayloadFilledWithin(
            1_000,
            static fn(): StoredPayload => new StoredPayload(['configs' => []], 1_000, 'session-1'),
        );

        self::assertNotNull($store->refreshLockAcquiredWithin(0));
    }

    private function payloadFilePath(): string
    {
        return $this->storagePath
            . DIRECTORY_SEPARATOR . 'add45d3fbae45285dcd74320ecebb931a054c563edc414cd120865f8ff4e09f8'
            . DIRECTORY_SEPARATOR . 'payload.json';
    }
}
