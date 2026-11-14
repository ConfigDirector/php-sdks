<?php

declare(strict_types=1);

return [
    'server_key' => env('CONFIGDIRECTOR_SERVER_KEY'),
    'base_url' => env('CONFIGDIRECTOR_BASE_URL'),
    'mode' => env('CONFIGDIRECTOR_MODE'),
    'timeout' => env('CONFIGDIRECTOR_TIMEOUT'),
    'storage_path' => env('CONFIGDIRECTOR_STORAGE_PATH'),
];
