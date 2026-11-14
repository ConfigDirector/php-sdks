<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * @phpstan-type ScriptedStream array{status?: int, chunks?: list<string>}
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
     */
    public static function streaming(array $streamsInOrder): self
    {
        $folder = TemporaryFolder::create();
        $process = PhpProcess::running(
            __DIR__ . '/Processes/scripted-stream-server.php',
            [json_encode($streamsInOrder, JSON_THROW_ON_ERROR), self::chunksFileIn($folder)],
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
        return 'data: ' . json_encode($payload, JSON_THROW_ON_ERROR) . "\n\n";
    }

    public function sendToTheOpenStream(string $chunk): void
    {
        file_put_contents(
            self::chunksFileIn($this->folder),
            json_encode($chunk, JSON_THROW_ON_ERROR) . "\n",
            FILE_APPEND | LOCK_EX,
        );
    }

    public function stop(): void
    {
        $this->process->stop();
        TemporaryFolder::remove($this->folder);
    }

    private static function chunksFileIn(string $folder): string
    {
        return $folder . DIRECTORY_SEPARATOR . 'chunks.jsonl';
    }
}
