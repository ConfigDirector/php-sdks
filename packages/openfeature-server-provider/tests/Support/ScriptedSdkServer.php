<?php

declare(strict_types=1);

namespace ConfigDirector\OpenFeature\Tests\Support;

/**
 * @phpstan-type RecordedRequest array{method: string, path: string, headers: array<string, string>, body: mixed}
 */
final readonly class ScriptedSdkServer
{
    /**
     * @param resource $process
     * @param resource $standardOutput
     */
    private function __construct(
        private mixed $process,
        private mixed $standardOutput,
        private string $folder,
        public string $url,
    ) {}

    /**
     * @param array<string, mixed>|string $body
     */
    public static function answeringEveryRequestWith(int $status, array|string $body): self
    {
        $folder = TemporaryFolder::create();
        $process = proc_open(
            [
                PHP_BINARY,
                __DIR__ . '/Processes/scripted-sdk-server.php',
                (string) $status,
                is_string($body) ? $body : json_encode($body, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
                self::requestsFileIn($folder),
            ],
            [1 => ['pipe', 'w'], 2 => STDERR],
            $pipes,
        );
        if ($process === false) {
            TemporaryFolder::remove($folder);

            throw new \RuntimeException('The scripted SDK server could not be started.');
        }

        $address = rtrim((string) fgets($pipes[1]), "\r\n");
        if ($address === '') {
            proc_terminate($process);
            fclose($pipes[1]);
            proc_close($process);
            TemporaryFolder::remove($folder);

            throw new \RuntimeException('The scripted SDK server did not start.');
        }

        return new self($process, $pipes[1], $folder, "http://{$address}");
    }

    /**
     * @return list<RecordedRequest>
     */
    public function requestsReceived(): array
    {
        $recordedText = $this->textReadWhileNoRequestIsBeingRecorded();
        $endOfTheLastFinishedLine = strrpos($recordedText, "\n");
        if ($endOfTheLastFinishedLine === false) {
            return [];
        }

        $requests = [];
        foreach (explode("\n", substr($recordedText, 0, $endOfTheLastFinishedLine)) as $line) {
            $request = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            if (
                !is_array($request)
                || !is_string($request['method'] ?? null)
                || !is_string($request['path'] ?? null)
                || !is_array($request['headers'] ?? null)
            ) {
                throw new \RuntimeException("The scripted SDK server recorded an unreadable request: {$line}");
            }

            $headers = [];
            foreach ($request['headers'] as $name => $value) {
                $headers[(string) $name] = is_string($value) ? $value : '';
            }
            $requests[] = [
                'method' => $request['method'],
                'path' => $request['path'],
                'headers' => $headers,
                'body' => $request['body'] ?? null,
            ];
        }

        return $requests;
    }

    public function stop(): void
    {
        proc_terminate($this->process);
        fclose($this->standardOutput);
        proc_close($this->process);
        TemporaryFolder::remove($this->folder);
    }

    private function textReadWhileNoRequestIsBeingRecorded(): string
    {
        $requestsFile = self::requestsFileIn($this->folder);
        $openedRequestsFile = is_file($requestsFile) ? fopen($requestsFile, 'rb') : false;
        if ($openedRequestsFile === false) {
            return '';
        }

        try {
            flock($openedRequestsFile, LOCK_SH);

            return (string) stream_get_contents($openedRequestsFile);
        } finally {
            flock($openedRequestsFile, LOCK_UN);
            fclose($openedRequestsFile);
        }
    }

    private static function requestsFileIn(string $folder): string
    {
        return $folder . DIRECTORY_SEPARATOR . 'requests.jsonl';
    }
}
