<?php

declare(strict_types=1);

namespace ConfigDirector\Tests\Internal\LocalStore;

use ConfigDirector\Internal\LocalStore\AppendedLinesFile;
use ConfigDirector\Tests\Support\PhpProcess;
use ConfigDirector\Tests\Support\TemporaryFolder;
use PHPUnit\Framework\TestCase;

final class AppendedLinesFileProcessesTest extends TestCase
{
    private const int SIXTY_FOUR_MEBIBYTES = 64 * 1024 * 1024;

    private const int ONE_MEBIBYTE = 1024 * 1024;

    private string $storeFolder;

    /** @var list<PhpProcess> */
    private array $processesStillRunning = [];

    #[\Override]
    protected function setUp(): void
    {
        $this->storeFolder = TemporaryFolder::create();
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->processesStillRunning as $process) {
            $process->stop();
        }
        TemporaryFolder::remove($this->storeFolder);
    }

    public function testFiftyProcessesThatAppendALineOfOneMebibyteAtTheSameMomentAllHaveTheirLineReadBackWhole(): void
    {
        $fileAwaitedBeforeAppending = $this->storeFolder . DIRECTORY_SEPARATOR . 'append-the-lines';
        $paddingLetterOfEachProcess = str_split('abcdefghijklmnopqrstuvwxyABCDEFGHIJKLMNOPQRSTUVWXY');
        foreach ($paddingLetterOfEachProcess as $process => $paddingLetter) {
            $this->processesStillRunning[] = PhpProcess::running(
                __DIR__ . '/Processes/append-a-line-once-told.php',
                [
                    $this->storeFolder,
                    (string) self::SIXTY_FOUR_MEBIBYTES,
                    (string) $process,
                    $paddingLetter,
                    (string) self::ONE_MEBIBYTE,
                    $fileAwaitedBeforeAppending,
                ],
            );
        }
        foreach ($this->processesStillRunning as $process) {
            self::assertSame('started', $process->nextLineOfOutput());
        }

        touch($fileAwaitedBeforeAppending);
        $outputOfEachProcess = [];
        foreach ($this->processesStillRunning as $process) {
            $outputOfEachProcess[] = $process->finished()->output;
        }
        $this->processesStillRunning = [];

        $appendedLinesFile = new AppendedLinesFile($this->storeFolder, 'telemetry.jsonl', self::SIXTY_FOUR_MEBIBYTES);
        $bytesOfThePaddingReadBack = [];
        $lettersOfThePaddingReadBackByProcess = [];
        foreach ($appendedLinesFile->movedAside()?->linesReadOnce() ?? [] as $line) {
            $lineReadBack = json_decode($line, associative: true);
            $process = is_array($lineReadBack) ? $lineReadBack['process'] ?? null : null;
            $padding = is_array($lineReadBack) ? $lineReadBack['padding'] ?? null : null;
            $bytesOfThePaddingReadBack[] = is_string($padding) ? strlen($padding) : null;
            $lettersOfThePaddingReadBackByProcess[is_string($process) ? $process : 'unreadable'] = is_string($padding)
                ? count_chars($padding, 3)
                : null;
        }
        ksort($lettersOfThePaddingReadBackByProcess);

        self::assertSame(array_fill(0, 50, 'appended'), $outputOfEachProcess);
        self::assertSame(array_fill(0, 50, 1_048_576), $bytesOfThePaddingReadBack);
        self::assertSame(
            'abcdefghijklmnopqrstuvwxyABCDEFGHIJKLMNOPQRSTUVWXY',
            implode('', $lettersOfThePaddingReadBackByProcess),
        );
    }
}
