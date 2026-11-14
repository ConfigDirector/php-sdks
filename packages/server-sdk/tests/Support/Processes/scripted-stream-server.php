<?php

declare(strict_types=1);

if (!isset($argv)) {
    throw new RuntimeException('This script takes its arguments from the command line.');
}
[, , $scriptJson, $requestsFile, $commandsFile] = $argv;

set_error_handler(static fn(): bool => true);

$script = json_decode($scriptJson, true, flags: JSON_THROW_ON_ERROR);
if (!is_array($script)) {
    throw new RuntimeException('The script must be a JSON object.');
}
$streamsLeft = is_array($script['streams'] ?? null) ? array_values($script['streams']) : [];
$answersByPath = is_array($script['answersByPath'] ?? null) ? $script['answersByPath'] : [];

$server = stream_socket_server('tcp://127.0.0.1:0');
if ($server === false) {
    throw new RuntimeException('The scripted stream server could not listen on a local port.');
}
stream_set_blocking($server, false);
echo stream_socket_get_name($server, false) . "\n";

/**
 * @param resource $connection
 * @param array<array-key, mixed> $headers
 */
function answerAndClose(mixed $connection, int $status, array $headers, string $body): void
{
    $reasonPhrases = [200 => 'OK', 204 => 'No Content', 401 => 'Unauthorized', 429 => 'Too Many Requests'];
    $head = sprintf("HTTP/1.1 %d %s\r\nConnection: close\r\n", $status, $reasonPhrases[$status] ?? 'Scripted');
    foreach ($headers as $name => $value) {
        $head .= sprintf("%s: %s\r\n", $name, is_string($value) ? $value : '');
    }
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
/** @var list<resource> $connectionsHeldWithoutAnAnswer */
$connectionsHeldWithoutAnAnswer = [];
$commandsRead = 0;
/** @var list<array<array-key, mixed>> $commandsLeft */
$commandsLeft = [];

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
        [$method, $path] = [...explode(' ', (string) array_shift($headerLines)), ''];
        $headers = [];
        foreach ($headerLines as $headerLine) {
            [$name, $value] = [...explode(':', $headerLine, 2), ''];
            $headers[strtolower(trim($name))] = trim($value);
        }
        if (strlen($body) < (int) ($headers['content-length'] ?? '0')) {
            continue;
        }
        unset($unansweredConnections[$connectionId]);
        stream_set_blocking($connection, true);

        file_put_contents(
            $requestsFile,
            json_encode(
                [
                    'method' => $method,
                    'path' => $path,
                    'headers' => $headers,
                    'body' => json_decode($body, true),
                    'bodyText' => $body,
                ],
                JSON_THROW_ON_ERROR,
            ) . "\n",
            FILE_APPEND | LOCK_EX,
        );

        if ($path !== '/server/sse/v1') {
            $scriptedAnswer = is_array($answersByPath[$path] ?? null) ? $answersByPath[$path] : [];
            $defaultStatus = $path === '/server/heartbeat/v1' ? 204 : 200;
            answerAndClose(
                $connection,
                is_int($scriptedAnswer['status'] ?? null) ? $scriptedAnswer['status'] : $defaultStatus,
                ['Content-Type' => 'application/json'],
                is_string($scriptedAnswer['body'] ?? null) ? $scriptedAnswer['body'] : '{}',
            );

            continue;
        }

        $stream = array_shift($streamsLeft);
        if (!is_array($stream)) {
            $stream = ['status' => 500, 'body' => 'The scripted stream server has no stream left.'];
        }
        if (($stream['holdsWithoutAnswering'] ?? false) === true) {
            $connectionsHeldWithoutAnAnswer[] = $connection;

            continue;
        }
        $status = is_int($stream['status'] ?? null) ? $stream['status'] : 200;
        $streamHeaders = is_array($stream['headers'] ?? null) ? $stream['headers'] : [];
        if ($status !== 200) {
            answerAndClose(
                $connection,
                $status,
                $streamHeaders,
                is_string($stream['body'] ?? null) ? $stream['body'] : '',
            );

            continue;
        }

        $head = "HTTP/1.1 200 OK\r\nConnection: close\r\nContent-Type: text/event-stream\r\n";
        foreach ($streamHeaders as $name => $value) {
            $head .= sprintf("%s: %s\r\n", $name, is_string($value) ? $value : '');
        }
        fwrite($connection, $head . "\r\n");
        foreach (is_array($stream['chunks'] ?? null) ? $stream['chunks'] : [] as $streamChunk) {
            fwrite($connection, is_string($streamChunk) ? $streamChunk : '');
        }
        if (($stream['closes'] ?? false) === true) {
            fclose($connection);

            continue;
        }
        if ($openStream !== null) {
            fclose($openStream);
        }
        $openStream = $connection;
    }

    $commandsText = is_file($commandsFile) ? (string) file_get_contents($commandsFile) : '';
    $endOfTheLastFinishedLine = strrpos($commandsText, "\n");
    $finishedCommandLines = $endOfTheLastFinishedLine === false
        ? []
        : explode("\n", substr($commandsText, 0, $endOfTheLastFinishedLine));
    foreach (array_slice($finishedCommandLines, $commandsRead) as $commandLine) {
        $commandsRead++;
        $command = json_decode($commandLine, true);
        if (is_array($command)) {
            $commandsLeft[] = $command;
        }
    }
    $streamsWithSomethingToRead = $openStream === null ? [] : [$openStream];
    $writable = null;
    $failed = null;
    if ($openStream !== null && stream_select($streamsWithSomethingToRead, $writable, $failed, 0, 0) > 0) {
        $sentByTheClient = fread($openStream, 8_192);
        if (($sentByTheClient === '' || $sentByTheClient === false) && feof($openStream)) {
            file_put_contents(
                $requestsFile,
                json_encode(
                    ['method' => 'CLOSE', 'path' => '/stream-closed-by-the-client', 'headers' => []],
                    JSON_THROW_ON_ERROR,
                ) . "\n",
                FILE_APPEND | LOCK_EX,
            );
            fclose($openStream);
            $openStream = null;
        }
    }

    while ($openStream !== null && $commandsLeft !== []) {
        $command = array_shift($commandsLeft);
        if (is_string($command['chunk'] ?? null)) {
            fwrite($openStream, $command['chunk']);
        }
        if (($command['closes'] ?? false) === true) {
            fclose($openStream);
            $openStream = null;
        }
    }
}
