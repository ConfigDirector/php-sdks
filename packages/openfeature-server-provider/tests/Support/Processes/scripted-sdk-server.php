<?php

declare(strict_types=1);

if (!isset($argv)) {
    throw new RuntimeException('This script takes its arguments from the command line.');
}
[, $status, $answerBody, $requestsFile] = $argv;

set_error_handler(static fn(): bool => true);

$server = stream_socket_server('tcp://127.0.0.1:0');
if ($server === false) {
    throw new RuntimeException('The scripted SDK server could not listen on a local port.');
}
echo stream_socket_get_name($server, false) . "\n";

for (;;) {
    $connection = stream_socket_accept($server, 3_600.0);
    if ($connection === false) {
        continue;
    }

    $head = '';
    while (!str_contains($head, "\r\n\r\n")) {
        $chunk = fread($connection, 8_192);
        if ($chunk === false || $chunk === '') {
            break;
        }
        $head .= $chunk;
    }
    if ($head === '') {
        fclose($connection);

        continue;
    }

    [$requestLineAndHeaders, $body] = [...explode("\r\n\r\n", $head, 2), ''];
    $headerLines = explode("\r\n", $requestLineAndHeaders);
    [$method, $path] = [...explode(' ', (string) array_shift($headerLines)), ''];
    $headers = [];
    foreach ($headerLines as $headerLine) {
        [$name, $value] = [...explode(':', $headerLine, 2), ''];
        $headers[strtolower(trim($name))] = trim($value);
    }
    $bodyBytes = (int) ($headers['content-length'] ?? '0');
    while (strlen($body) < $bodyBytes) {
        $chunk = fread($connection, max(1, $bodyBytes - strlen($body)));
        if ($chunk === false || $chunk === '') {
            break;
        }
        $body .= $chunk;
    }

    file_put_contents(
        $requestsFile,
        json_encode(
            ['method' => $method, 'path' => $path, 'headers' => $headers, 'body' => json_decode($body, true)],
            JSON_THROW_ON_ERROR,
        ) . "\n",
        FILE_APPEND | LOCK_EX,
    );

    $answerBytes = strlen($answerBody);
    fwrite(
        $connection,
        "HTTP/1.1 {$status} Scripted\r\nConnection: close\r\nContent-Type: application/json\r\n"
        . "Content-Length: {$answerBytes}\r\n\r\n{$answerBody}",
    );
    fclose($connection);
}
