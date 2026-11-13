<?php

declare(strict_types=1);

namespace ConfigDirector\Tests\Internal\LocalStore;

use ConfigDirector\Internal\LocalStore\LocalStore;
use ConfigDirector\Internal\LocalStore\StoredPayload;
use ConfigDirector\Tests\Support\PhpProcess;
use ConfigDirector\Tests\Support\RecordingLogger;
use ConfigDirector\Tests\Support\TemporaryFolder;
use PHPUnit\Framework\Attributes\RequiresOperatingSystem;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

final class LocalStoreProcessesTest extends TestCase
{
    private const array SMALLEST_FILE_SIZE_LIMIT_OF_THE_SHELL = ['sh', '-c', 'ulimit -f 64; exec "$@"', 'sh'];

    private const int SIGNAL_OF_A_WRITE_PAST_THE_FILE_SIZE_LIMIT = 25;

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

    public function testAPayloadStoredByOneProcessIsReadWholeByAProcessStartedAfterwards(): void
    {
        $storingOutput = PhpProcess::running(
            __DIR__ . '/Processes/store-payload.php',
            [$this->storagePath, 'server-sdk-key', 'stored-by-the-first-process', '2000000'],
        )->finished()->output;

        $readingOutput = PhpProcess::running(
            __DIR__ . '/Processes/print-stored-payload.php',
            [$this->storagePath, 'server-sdk-key'],
        )->finished()->output;

        self::assertSame('stored', $storingOutput);
        self::assertSame(
            [
                'payload' => [
                    'configs' => [],
                    'marker' => 'stored-by-the-first-process',
                    'padding' => str_repeat('x', 2_000_000),
                ],
                'confirmedFreshAtEpochMilliseconds' => 1_794_477_600_123,
                'sessionId' => 'session-of-stored-by-the-first-process',
                'errorsLogged' => 0,
            ],
            json_decode($readingOutput, true),
        );
    }

    public function testTenProcessesThatFindTheStoreEmptyAtOnceFetchOnceAndAllReadWhatThatOneStored(): void
    {
        $startingGateFolder = $this->storagePath . DIRECTORY_SEPARATOR . 'starting-gate';
        mkdir($startingGateFolder);
        $processes = [];
        foreach (['p1', 'p2', 'p3', 'p4', 'p5', 'p6', 'p7', 'p8', 'p9', 'p10'] as $processName) {
            $processes[] = PhpProcess::running(
                __DIR__ . '/Processes/fill-empty-store.php',
                [$this->storagePath, 'server-sdk-key', $startingGateFolder, $processName],
            );
        }
        $giveUpAt = hrtime(true) + 60_000_000_000;
        while (count(TemporaryFolder::entriesOf($startingGateFolder)) < 10 && hrtime(true) < $giveUpAt) {
            usleep(1_000);
        }
        self::assertCount(10, TemporaryFolder::entriesOf($startingGateFolder));

        touch($startingGateFolder . DIRECTORY_SEPARATOR . 'go');
        $processesThatFetched = [];
        $processesWhosePayloadWasRead = [];
        foreach ($processes as $process) {
            $result = json_decode($process->finished()->output, true);
            self::assertIsArray($result);
            self::assertIsString($result['fetchedBy']);
            if ($result['hasFetched'] === true) {
                $processesThatFetched[] = $result['process'];
            }
            $processesWhosePayloadWasRead[] = $result['fetchedBy'];
        }

        self::assertCount(1, $processesThatFetched);
        self::assertCount(10, $processesWhosePayloadWasRead);
        self::assertSame($processesThatFetched, array_values(array_unique($processesWhosePayloadWasRead)));
    }

    public function testAProcessThatWaitedForTheRefreshLockIsGivenWhatIsLeftOfTheTimeoutToFetch(): void
    {
        $store = LocalStore::ofServerSdkKey('server-sdk-key', $this->storagePath, new RecordingLogger());
        $holdingProcess = PhpProcess::running(
            __DIR__ . '/Processes/keep-the-refresh-lock.php',
            [$this->storagePath, 'server-sdk-key', '400'],
        );
        $remainingMillisecondsGiven = null;

        $lineOfTheHolder = $holdingProcess->nextLineOfOutput();
        $store->storedPayloadFilledWithin(
            3_000,
            static function (int $remainingMilliseconds) use (&$remainingMillisecondsGiven): ?StoredPayload {
                $remainingMillisecondsGiven = $remainingMilliseconds;

                return null;
            },
        );
        $holdingProcess->finished();

        self::assertSame('locked', $lineOfTheHolder);
        self::assertGreaterThan(1_000, $remainingMillisecondsGiven);
        self::assertLessThanOrEqual(2_700, $remainingMillisecondsGiven);
    }

    public function testAProcessThatWaitedForTheRefreshLockPastTheTimeoutReadsWhatTheHolderStoredMeanwhile(): void
    {
        $store = LocalStore::ofServerSdkKey('server-sdk-key', $this->storagePath, new RecordingLogger());
        $holdingProcess = PhpProcess::running(
            __DIR__ . '/Processes/store-payload-while-keeping-the-lock.php',
            [$this->storagePath, 'server-sdk-key', '150', '600'],
        );
        $fetches = 0;

        $lineOfTheHolder = $holdingProcess->nextLineOfOutput();
        $filledPayload = $store->storedPayloadFilledWithin(
            500,
            static function () use (&$fetches): StoredPayload {
                $fetches++;

                return new StoredPayload(
                    ['configs' => [], 'marker' => 'fetched-by-the-waiting-process'],
                    2_000,
                    'session-2',
                );
            },
        );
        $holdingProcess->finished();

        self::assertSame('locked', $lineOfTheHolder);
        self::assertSame(0, $fetches);
        self::assertEquals(
            new StoredPayload(['configs' => [], 'marker' => 'stored-by-the-holder'], 1_000, 'session-1'),
            $filledPayload,
        );
    }

    #[RequiresOperatingSystem('Linux|Darwin')]
    public function testAProcessKilledWhileStoringAPayloadLeavesThePreviousPayloadReadable(): void
    {
        $this->storeInThisProcess(
            new StoredPayload(['configs' => [], 'marker' => 'stored-before-the-kill'], 1_000, 'session-1'),
        );

        $killedProcess = PhpProcess::running(
            __DIR__ . '/Processes/store-payload.php',
            [$this->storagePath, 'server-sdk-key', 'never-stored', '4000000'],
            commandPrefix: self::SMALLEST_FILE_SIZE_LIMIT_OF_THE_SHELL,
        )->finished();

        $logger = new RecordingLogger();
        self::assertSame(self::SIGNAL_OF_A_WRITE_PAST_THE_FILE_SIZE_LIMIT, $killedProcess->terminatingSignal);
        self::assertSame('', $killedProcess->output);
        self::assertEquals(
            new StoredPayload(['configs' => [], 'marker' => 'stored-before-the-kill'], 1_000, 'session-1'),
            LocalStore::ofServerSdkKey('server-sdk-key', $this->storagePath, $logger)->storedPayload(),
        );
        self::assertSame([], $logger->contextsLoggedAt(LogLevel::ERROR));
    }

    #[RequiresOperatingSystem('Linux|Darwin')]
    public function testAfterAProcessWasKilledWhileStoringAPayloadTheNextPayloadIsStored(): void
    {
        $this->storeInThisProcess(
            new StoredPayload(['configs' => [], 'marker' => 'stored-before-the-kill'], 1_000, 'session-1'),
        );
        $killedProcess = PhpProcess::running(
            __DIR__ . '/Processes/store-payload.php',
            [$this->storagePath, 'server-sdk-key', 'never-stored', '4000000'],
            commandPrefix: self::SMALLEST_FILE_SIZE_LIMIT_OF_THE_SHELL,
        )->finished();

        $this->storeInThisProcess(
            new StoredPayload(['configs' => [], 'marker' => 'stored-after-the-kill'], 2_000, 'session-1'),
        );

        self::assertSame(self::SIGNAL_OF_A_WRITE_PAST_THE_FILE_SIZE_LIMIT, $killedProcess->terminatingSignal);

        self::assertEquals(
            new StoredPayload(['configs' => [], 'marker' => 'stored-after-the-kill'], 2_000, 'session-1'),
            LocalStore::ofServerSdkKey('server-sdk-key', $this->storagePath, new RecordingLogger())->storedPayload(),
        );
        self::assertSame(
            ['lock', 'payload.json'],
            TemporaryFolder::entriesOf(
                $this->storagePath . '/add45d3fbae45285dcd74320ecebb931a054c563edc414cd120865f8ff4e09f8',
            ),
        );
    }

    public function testAProcessReadingWhileAnotherKeepsReplacingThePayloadAlwaysReadsAWholePayload(): void
    {
        $this->storeInThisProcess(
            new StoredPayload(['configs' => [], 'padding' => str_repeat('a', 300_000)], 0, 'session-1'),
        );
        $logger = new RecordingLogger();
        $store = LocalStore::ofServerSdkKey('server-sdk-key', $this->storagePath, $logger);

        $replacingProcess = PhpProcess::running(
            __DIR__ . '/Processes/replace-payload-repeatedly.php',
            [$this->storagePath, 'server-sdk-key', '1000', '300000'],
        );
        $readsThatWereNotWhole = 0;
        $reads = 0;
        while ($replacingProcess->isRunning()) {
            $padding = $store->storedPayload()?->payload['padding'] ?? null;
            $isWhole = is_string($padding) && strlen($padding) === 300_000 && strlen(count_chars($padding, 3)) === 1;
            $readsThatWereNotWhole += $isWhole ? 0 : 1;
            $reads++;
        }

        self::assertSame('1000', $replacingProcess->finished()->output);
        self::assertGreaterThan(10, $reads);
        self::assertSame(0, $readsThatWereNotWhole);
        self::assertSame([], $logger->contextsLoggedAt(LogLevel::ERROR));
    }

    public function testAPayloadLargerThan32MebibytesIsNotStoredAndThePreviousPayloadStays(): void
    {
        $this->storeInThisProcess(
            new StoredPayload(['configs' => [], 'marker' => 'stored-before'], 1_000, 'session-1'),
        );

        $output = PhpProcess::running(
            __DIR__ . '/Processes/store-payload.php',
            [$this->storagePath, 'server-sdk-key', 'too-large', (string) (32 * 1024 * 1024)],
            ['memory_limit' => '512M'],
        )->finished()->output;

        self::assertSame('not-stored', $output);
        self::assertEquals(
            new StoredPayload(['configs' => [], 'marker' => 'stored-before'], 1_000, 'session-1'),
            LocalStore::ofServerSdkKey('server-sdk-key', $this->storagePath, new RecordingLogger())->storedPayload(),
        );
    }

    private function storeInThisProcess(StoredPayload $storedPayload): void
    {
        $refreshLock = LocalStore::ofServerSdkKey('server-sdk-key', $this->storagePath, new RecordingLogger())
            ->refreshLockAcquiredWithin(0);
        self::assertNotNull($refreshLock);
        $refreshLock->replaceStoredPayload($storedPayload);
        $refreshLock->release();
    }
}
