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
final readonly class ScriptedSdkServer
{
    private function __construct(
        private PhpProcess $process,
        private string $folder,
        public string $url,
    ) {}

    /**
     * @param list<ScriptedAnswer> $answersInOrder
     */
    public static function answering(array $answersInOrder): self
    {
        return self::started($answersInOrder, answersOverTlsWithASelfSignedCertificate: false);
    }

    /**
     * @param list<ScriptedAnswer> $answersInOrder
     */
    public static function answeringOverTlsWithASelfSignedCertificate(array $answersInOrder): self
    {
        return self::started($answersInOrder, answersOverTlsWithASelfSignedCertificate: true);
    }

    /**
     * @param ScriptedAnswer $answer
     */
    public static function answeringEveryRequestWith(array $answer): self
    {
        return self::started(
            answersInOrder: [],
            answersOverTlsWithASelfSignedCertificate: false,
            answerOnceTheScriptHasRunOut: $answer,
        );
    }

    /**
     * @param list<ScriptedAnswer> $answersInOrder
     * @param ScriptedAnswer $answerOnceTheScriptHasRunOut
     */
    private static function started(
        array $answersInOrder,
        bool $answersOverTlsWithASelfSignedCertificate,
        array $answerOnceTheScriptHasRunOut = [
            'status' => 500,
            'body' => 'The scripted SDK server has no answer left.',
        ],
    ): self {
        $folder = TemporaryFolder::create();
        $process = PhpProcess::running(
            __DIR__ . '/Processes/scripted-sdk-server.php',
            [
                json_encode(array_map(self::withItsBodyAsText(...), $answersInOrder), JSON_THROW_ON_ERROR),
                self::requestsFileIn($folder),
                $answersOverTlsWithASelfSignedCertificate ? $folder . DIRECTORY_SEPARATOR . 'certificate.pem' : '',
                json_encode(self::withItsBodyAsText($answerOnceTheScriptHasRunOut), JSON_THROW_ON_ERROR),
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

    /**
     * @param ScriptedAnswer $answer
     *
     * @return ScriptedAnswer
     */
    private static function withItsBodyAsText(array $answer): array
    {
        if (is_array($answer['body'] ?? null)) {
            $answer['body'] = json_encode($answer['body'], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        }

        return $answer;
    }

    private static function requestsFileIn(string $folder): string
    {
        return $folder . DIRECTORY_SEPARATOR . 'requests.jsonl';
    }
}
