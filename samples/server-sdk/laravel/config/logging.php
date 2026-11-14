<?php

declare(strict_types=1);

use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Processor\PsrLogMessageProcessor;

return [
    'default' => env('LOG_CHANNEL', 'stderr'),

    'channels' => [
        'configdirector' => [
            'driver' => 'monolog',
            'handler' => StreamHandler::class,
            'handler_with' => ['stream' => 'php://stderr'],
            'formatter' => LineFormatter::class,
            'formatter_with' => ['dateFormat' => 'Y-m-d H:i:s', 'ignoreEmptyContextAndExtra' => true],
            'level' => env('CONFIGDIRECTOR_LOG_LEVEL') ?: 'info',
            'processors' => [
                ['processor' => PsrLogMessageProcessor::class, 'with' => ['removeUsedContextFields' => true]],
            ],
        ],
    ],
];
