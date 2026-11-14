<?php

declare(strict_types=1);

if (!isset($argv)) {
    throw new RuntimeException('This script takes its arguments from the command line.');
}
[, $streamsJson, $chunksFile] = $argv;

set_error_handler(static fn(): bool => true);

$streamsLeft = json_decode($streamsJson, true, flags: JSON_THROW_ON_ERROR);
if (!is_array($streamsLeft)) {
    throw new RuntimeException('The streams must be a JSON list.');
}

$server = stream_socket_server('tcp://127.0.0.1:0');
if ($server === false) {
    throw new RuntimeException('The scripted stream server could not listen on a local port.');
}
stream_set_blocking($server, false);
echo stream_socket_get_name($server, false) . "\n";

/**
 * @param resource $connection
 */
function answerAndClose(mixed $connection, int $status, string $body): void
{
    $head = "HTTP/1.1 {$status} Scripted\r\nConnection: close\r\nContent-Type: application/json\r\n";
    if ($status !== 204) {
        $head .= 'Content-Length: ' . strlen($body) . "\r\n";
    }
    fwrite($connection, $head . "\r\n" . ($status === 204 ? '' : $body));
    fclose($connection);
}

/** @var array<int, array{connection: resource, received: string}> $unansweredConnections */
$unansweredConnections = [];
/** @var resource|null $openStream */
$openStream = null;
$chunksSent = 0;

for (;;) {
    $readable = [$server, ...array_column($unansweredConnections, 'connection')];
    $writable = null;
    $failed = null;
    stream_select($readable, $writable, $failed, 0, 5_000);

    $accepted = stream_socket_accept($server, 0.0);
    if ($accepted !== false) {
        stream_set_blocking($accepted, false);
        $unansweredConnections[(int) $accepted] = ['connection' => $accepted, 'received' => ''];
    }

    foreach ($unansweredConnections as $connectionId => $unansweredConnection) {
        $connection = $unansweredConnection['connection'];
        $chunk = fread($connection, 65_536);
        $received = $unansweredConnection['received'] . ($chunk === false ? '' : $chunk);
        $unansweredConnections[$connectionId]['received'] = $received;
        if ($received === '' && feof($connection)) {
            fclose($connection);
            unset($unansweredConnections[$connectionId]);

            continue;
        }
        if (!str_contains($received, "\r\n\r\n")) {
            continue;
        }

        [$requestLineAndHeaders, $body] = [...explode("\r\n\r\n", $received, 2), ''];
        $headerLines = explode("\r\n", $requestLineAndHeaders);
        [, $path] = [...explode(' ', (string) array_shift($headerLines)), ''];
        $contentLength = 0;
        foreach ($headerLines as $headerLine) {
            [$name, $value] = [...explode(':', $headerLine, 2), ''];
            if (strtolower(trim($name)) === 'content-length') {
                $contentLength = (int) trim($value);
            }
        }
        if (strlen($body) < $contentLength) {
            continue;
        }
        unset($unansweredConnections[$connectionId]);
        stream_set_blocking($connection, true);

        if ($path !== '/server/sse/v1') {
            answerAndClose($connection, $path === '/server/heartbeat/v1' ? 204 : 200, '{}');

            continue;
        }

        $stream = array_shift($streamsLeft);
        if (!is_array($stream)) {
            answerAndClose($connection, 500, 'The scripted stream server has no stream left.');

            continue;
        }
        $status = is_int($stream['status'] ?? null) ? $stream['status'] : 200;
        if ($status !== 200) {
            answerAndClose($connection, $status, '');

            continue;
        }

        fwrite($connection, "HTTP/1.1 200 OK\r\nConnection: close\r\nContent-Type: text/event-stream\r\n\r\n");
        foreach (is_array($stream['chunks'] ?? null) ? $stream['chunks'] : [] as $streamChunk) {
            fwrite($connection, is_string($streamChunk) ? $streamChunk : '');
        }
        if ($openStream !== null) {
            fclose($openStream);
        }
        $openStream = $connection;
    }

    if ($openStream === null) {
        continue;
    }

    $chunksText = is_file($chunksFile) ? (string) file_get_contents($chunksFile) : '';
    $endOfTheLastFinishedLine = strrpos($chunksText, "\n");
    $finishedChunkLines = $endOfTheLastFinishedLine === false
        ? []
        : explode("\n", substr($chunksText, 0, $endOfTheLastFinishedLine));
    foreach (array_slice($finishedChunkLines, $chunksSent) as $chunkLine) {
        $chunksSent++;
        $chunk = json_decode($chunkLine);
        fwrite($openStream, is_string($chunk) ? $chunk : '');
    }
}
