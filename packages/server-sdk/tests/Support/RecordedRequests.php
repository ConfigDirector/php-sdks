<?php

declare(strict_types=1);

namespace ConfigDirector\Tests\Support;

/**
 * @phpstan-type RecordedRequest array{
 *     method: string,
 *     path: string,
 *     headers: array<string, string>,
 *     body: mixed,
 *     bodyText: string,
 * }
 */
final class RecordedRequests
{
    private function __construct() {}

    /**
     * @return list<RecordedRequest>
     */
    public static function in(string $requestsFile): array
    {
        $recordedText = self::textReadWhileNoRequestIsBeingRecordedIn($requestsFile);
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
                throw new \RuntimeException("The scripted server recorded an unreadable request: {$line}");
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
                'bodyText' => is_string($request['bodyText'] ?? null) ? $request['bodyText'] : '',
            ];
        }

        return $requests;
    }

    private static function textReadWhileNoRequestIsBeingRecordedIn(string $requestsFile): string
    {
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
}
