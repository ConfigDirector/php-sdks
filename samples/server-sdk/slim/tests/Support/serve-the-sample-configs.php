<?php

declare(strict_types=1);

$environmentId = '3f1b6c0a-52d4-4e8b-9a7c-0d2e4f6a8b10';
$payload = json_encode(
    [
        'payloadVersion' => 1,
        'environmentId' => $environmentId,
        'projectId' => '9c4d2e7f-1a3b-4c5d-8e6f-7a8b9c0d1e20',
        'configs' => [
            'temporary-feature-flag' => [
                'id' => '7b0c4f52-3d1e-4a86-9c2f-1e5d8a6b4c01',
                'key' => 'temporary-feature-flag',
                'type' => 'boolean',
                'typeConstraints' => null,
                'variations' => [],
                'target' => [
                    'environmentId' => $environmentId,
                    'rules' => [],
                    'defaultValue' => 'false',
                    'defaultValueId' => 'value-flag-off',
                ],
            ],
            'permanent-kill-switch' => [
                'id' => '2f6d1c3e-8a47-4b55-9d0e-6c1f7a9b2e02',
                'key' => 'permanent-kill-switch',
                'type' => 'boolean',
                'typeConstraints' => null,
                'variations' => [],
                'target' => [
                    'environmentId' => $environmentId,
                    'rules' => [],
                    'defaultValue' => 'true',
                    'defaultValueId' => 'value-kill-switch-on',
                ],
            ],
            'integer-config' => [
                'id' => '9a3e5b71-6c2d-4f08-8b1a-4d7c2e9f5a03',
                'key' => 'integer-config',
                'type' => 'integer',
                'typeConstraints' => null,
                'variations' => [],
                'target' => [
                    'environmentId' => $environmentId,
                    'rules' => [],
                    'defaultValue' => '42',
                    'defaultValueId' => 'value-integer-42',
                ],
            ],
            'day-of-the-week-config' => [
                'id' => '5c8a2d94-1b7f-4e63-a0d5-9f3b6c1e7d04',
                'key' => 'day-of-the-week-config',
                'type' => 'string',
                'typeConstraints' => null,
                'variations' => [],
                'target' => [
                    'environmentId' => $environmentId,
                    'rules' => [
                        [
                            'id' => 'rule-pro-plan',
                            'order' => 0,
                            'type' => 'conditional',
                            'target' => 'value',
                            'percentages' => null,
                            'value' => 'Tuesday',
                            'valueId' => 'value-tuesday',
                            'conditions' => [
                                [
                                    'kind' => 'attribute',
                                    'id' => 'condition-pro-plan',
                                    'attribute' => 'traits',
                                    'trait' => '/plan',
                                    'operator' => 'equals',
                                    'targetType' => 'text',
                                    'targetValues' => ['pro'],
                                ],
                            ],
                        ],
                    ],
                    'defaultValue' => 'Monday',
                    'defaultValueId' => 'value-monday',
                ],
            ],
            'json-value-config' => [
                'id' => 'e1d7f3a6-4c9b-4d21-b8e0-2a6f5c3d9b05',
                'key' => 'json-value-config',
                'type' => 'json',
                'typeConstraints' => null,
                'variations' => [],
                'target' => [
                    'environmentId' => $environmentId,
                    'rules' => [],
                    'defaultValue' => '{"greeting":"hello","sizes":[1,2]}',
                    'defaultValueId' => 'value-json-greeting',
                ],
            ],
        ],
        'segments' => new stdClass(),
        'kind' => 'full',
        'timestamp' => '2026-11-12T12:00:00.000000Z',
    ],
    JSON_THROW_ON_ERROR,
);

set_error_handler(static fn(): bool => true);

$server = stream_socket_server('tcp://127.0.0.1:0');
if ($server === false) {
    throw new RuntimeException('The SDK server of the sample tests could not listen on a local port.');
}
echo stream_socket_get_name($server, false) . "\n";

for (;;) {
    $connection = stream_socket_accept($server, 3_600.0);
    if ($connection === false) {
        continue;
    }

    $request = '';
    while (!str_contains($request, "\r\n\r\n")) {
        $chunk = fread($connection, 8_192);
        if ($chunk === false || $chunk === '') {
            break;
        }
        $request .= $chunk;
    }
    [$head, $body] = [...explode("\r\n\r\n", $request, 2), ''];
    $bodyBytes = preg_match('/^Content-Length:\s*(\d+)/mi', $head, $contentLength) === 1 ? (int) $contentLength[1] : 0;
    while (strlen($body) < $bodyBytes) {
        $chunk = fread($connection, max(1, $bodyBytes - strlen($body)));
        if ($chunk === false || $chunk === '') {
            break;
        }
        $body .= $chunk;
    }
    if ($request !== '') {
        fwrite($connection, sprintf(
            "HTTP/1.1 200 OK\r\nConnection: close\r\nContent-Type: application/json\r\nContent-Length: %d\r\n\r\n%s",
            strlen($payload),
            $payload,
        ));
    }
    fclose($connection);
}
