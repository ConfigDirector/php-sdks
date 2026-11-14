<?php

declare(strict_types=1);

namespace ConfigDirector\Tests\Support;

/**
 * @phpstan-import-type RecordedRequest from RecordedRequests
 * @phpstan-type ScriptedStream array{
 *     status?: int,
 *     headers?: array<string, string>,
 *     body?: string,
 *     chunks?: list<string>,
 *     closes?: bool,
 *     holdsWithoutAnswering?: bool,
 * }
 */
final class ScriptedStreamServer
{
    private function __construct(
        private readonly PhpProcess $process,
        private readonly string $folder,
        public readonly string $url,
    ) {}

    /**
     * @param list<ScriptedStream> $streamsInOrder
     * @param array<string, array{status?: int, body?: string}> $answersByPath
     */
    public static function streaming(array $streamsInOrder, array $answersByPath = []): self
    {
        $folder = TemporaryFolder::create();
        $process = PhpProcess::running(
            __DIR__ . '/Processes/scripted-stream-server.php',
            [
                json_encode(['streams' => $streamsInOrder, 'answersByPath' => $answersByPath], JSON_THROW_ON_ERROR),
                self::requestsFileIn($folder),
                self::commandsFileIn($folder),
            ],
        );
        $address = $process->nextLineOfOutput();
        if ($address === '') {
            $process->stop();
            TemporaryFolder::remove($folder);

            throw new \RuntimeException('The scripted stream server did not start.');
        }

        return new self($process, $folder, "http://{$address}");
    }

    /**
     * @param array<array-key, mixed> $payload
     */
    public static function eventCarrying(array $payload): string
    {
        return 'data: ' . json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION) . "\n\n";
    }

    public function sendToTheOpenStream(string $chunk): void
    {
        $this->command(['chunk' => $chunk]);
    }

    public function closeTheOpenStream(): void
    {
        $this->command(['closes' => true]);
    }

    /**
     * @return list<RecordedRequest>
     */
    public function requestsReceived(): array
    {
        return RecordedRequests::in(self::requestsFileIn($this->folder));
    }

    /**
     * @return list<RecordedRequest>
     */
    public function requestsReceivedAt(string $path): array
    {
        return array_values(array_filter(
            $this->requestsReceived(),
            static fn(array $request): bool => $request['path'] === $path,
        ));
    }

    public function streamsClosedByTheClient(): int
    {
        return count($this->requestsReceivedAt('/stream-closed-by-the-client'));
    }

    public function stop(): void
    {
        $this->process->stop();
        TemporaryFolder::remove($this->folder);
    }

    /**
     * @param array{chunk?: string, closes?: bool} $command
     */
    private function command(array $command): void
    {
        file_put_contents(
            self::commandsFileIn($this->folder),
            json_encode($command, JSON_THROW_ON_ERROR) . "\n",
            FILE_APPEND | LOCK_EX,
        );
    }

    private static function requestsFileIn(string $folder): string
    {
        return $folder . DIRECTORY_SEPARATOR . 'requests.jsonl';
    }

    private static function commandsFileIn(string $folder): string
    {
        return $folder . DIRECTORY_SEPARATOR . 'commands.jsonl';
    }
}
