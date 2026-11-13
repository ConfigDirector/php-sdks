<?php

declare(strict_types=1);

if (!isset($argv)) {
    throw new RuntimeException('This script takes its arguments from the command line.');
}
[, , $answersJson, $requestsFile, $fileForASelfSignedCertificate] = $argv;

set_error_handler(static fn(): bool => true);

$answers = json_decode($answersJson, true, flags: JSON_THROW_ON_ERROR);
if (!is_array($answers)) {
    throw new RuntimeException('The scripted answers must be a JSON array.');
}

if ($fileForASelfSignedCertificate === '') {
    $server = stream_socket_server('tcp://127.0.0.1:0');
} else {
    $privateKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2_048]);
    if ($privateKey === false) {
        throw new RuntimeException('The scripted SDK server could not create a private key.');
    }
    $privateKeyOfTheRequest = $privateKey;
    $signingRequest = openssl_csr_new(
        ['commonName' => '127.0.0.1'],
        $privateKeyOfTheRequest,
        ['digest_alg' => 'sha256'],
    );
    if (!$signingRequest instanceof OpenSSLCertificateSigningRequest) {
        throw new RuntimeException('The scripted SDK server could not create a certificate signing request.');
    }
    $certificate = openssl_csr_sign($signingRequest, null, $privateKey, 1, ['digest_alg' => 'sha256']);
    if ($certificate === false) {
        throw new RuntimeException('The scripted SDK server could not sign its certificate.');
    }
    openssl_x509_export($certificate, $certificateText);
    openssl_pkey_export($privateKey, $privateKeyText);
    if (!is_string($certificateText) || !is_string($privateKeyText)) {
        throw new RuntimeException('The scripted SDK server could not export its certificate.');
    }
    file_put_contents($fileForASelfSignedCertificate, $certificateText . $privateKeyText);
    $server = stream_socket_server(
        'tls://127.0.0.1:0',
        context: stream_context_create(['ssl' => ['local_cert' => $fileForASelfSignedCertificate]]),
    );
}
if ($server === false) {
    throw new RuntimeException('The scripted SDK server could not listen on a local port.');
}
echo stream_socket_get_name($server, false) . "\n";

$reasonPhrases = [200 => 'OK', 204 => 'No Content', 401 => 'Unauthorized', 429 => 'Too Many Requests'];

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

    $answer = array_shift($answers);
    if (!is_array($answer)) {
        $answer = ['status' => 500, 'body' => 'The scripted SDK server has no answer left.'];
    }
    $delayMilliseconds = $answer['delayMilliseconds'] ?? 0;
    usleep((is_int($delayMilliseconds) ? $delayMilliseconds : 0) * 1_000);

    $closes = $answer['closes'] ?? null;
    if ($closes === 'before-answering') {
        fclose($connection);

        continue;
    }

    $status = is_int($answer['status'] ?? null) ? $answer['status'] : 200;
    $paddingBytes = is_int($answer['bodyBytes'] ?? null) ? $answer['bodyBytes'] : 0;
    $answerBody = is_string($answer['body'] ?? null) ? $answer['body'] : '';
    $answerBody .= str_repeat(' ', max(0, $paddingBytes - strlen($answerBody)));
    $answerHeaders = is_array($answer['headers'] ?? null) ? $answer['headers'] : [];
    $answerHead = sprintf("HTTP/1.1 %d %s\r\nConnection: close\r\n", $status, $reasonPhrases[$status] ?? 'Scripted');
    foreach ($answerHeaders as $name => $value) {
        $answerHead .= sprintf("%s: %s\r\n", $name, is_string($value) ? $value : '');
    }
    if ($status !== 204) {
        $announcedBytes = strlen($answerBody) + ($closes === 'mid-body' ? 100 : 0);
        $answerHead .= "Content-Type: application/json\r\nContent-Length: {$announcedBytes}\r\n";
    }

    fwrite($connection, $answerHead . "\r\n");
    foreach (str_split($answerBody, 1_048_576) as $bodyChunk) {
        if ($status === 204 || fwrite($connection, $bodyChunk) === false) {
            break;
        }
    }
    fclose($connection);
}
