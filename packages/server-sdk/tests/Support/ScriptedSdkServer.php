<?php

declare(strict_types=1);

namespace ConfigDirector\Tests\Support;

/**
 * @phpstan-import-type RecordedRequest from RecordedRequests
 * @phpstan-type ScriptedAnswer array{
 *     status?: int,
 *     headers?: array<string, string>,
 *     body?: string|array<array-key, mixed>,
 *     bodyBytes?: int,
 *     delayMilliseconds?: int,
 *     closes?: 'before-answering'|'mid-body',
 * }
 */
final class ScriptedSdkServer
{
    private function __construct(
        private readonly PhpProcess $process,
        private readonly string $folder,
        public readonly string $url,
    ) {}

    /**
     * @param list<ScriptedAnswer> $answersInOrder
     */
    public static function answering(array $answersInOrder): self
    {
        return self::started($answersInOrder, false);
    }

    /**
     * @param list<ScriptedAnswer> $answersInOrder
     */
    public static function answeringOverTlsWithASelfSignedCertificate(array $answersInOrder): self
    {
        return self::started($answersInOrder, true);
    }

    /**
     * @param list<ScriptedAnswer> $answersInOrder
     */
    private static function started(array $answersInOrder, bool $answersOverTlsWithASelfSignedCertificate): self
    {
        $answersWithEncodedBodies = [];
        foreach ($answersInOrder as $answer) {
            if (is_array($answer['body'] ?? null)) {
                $answer['body'] = json_encode($answer['body'], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
            }
            $answersWithEncodedBodies[] = $answer;
        }

        $folder = TemporaryFolder::create();
        $process = PhpProcess::running(
            __DIR__ . '/Processes/scripted-sdk-server.php',
            [
                json_encode($answersWithEncodedBodies, JSON_THROW_ON_ERROR),
                self::requestsFileIn($folder),
                $answersOverTlsWithASelfSignedCertificate ? $folder . DIRECTORY_SEPARATOR . 'certificate.pem' : '',
            ],
        );
        $address = $process->nextLineOfOutput();
        if ($address === '') {
            $process->stop();
            TemporaryFolder::remove($folder);

            throw new \RuntimeException('The scripted SDK server did not start.');
        }

        return new self(
            $process,
            $folder,
            ($answersOverTlsWithASelfSignedCertificate ? 'https' : 'http') . "://{$address}",
        );
    }

    /**
     * @return list<RecordedRequest>
     */
    public function requestsReceived(): array
    {
        return RecordedRequests::in(self::requestsFileIn($this->folder));
    }

    public function stop(): void
    {
        $this->process->stop();
        TemporaryFolder::remove($this->folder);
    }

    private static function requestsFileIn(string $folder): string
    {
        return $folder . DIRECTORY_SEPARATOR . 'requests.jsonl';
    }
}
