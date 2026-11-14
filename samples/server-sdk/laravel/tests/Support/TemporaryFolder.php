<?php

declare(strict_types=1);

namespace Tests\Support;

final class TemporaryFolder
{
    private function __construct() {}

    public static function create(): string
    {
        $folder = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'configdirector-sample-' . bin2hex(random_bytes(8));
        mkdir($folder, 0o700);

        return $folder;
    }

    public static function remove(string $folder): void
    {
        if (is_link($folder) || is_file($folder)) {
            unlink($folder);

            return;
        }
        if (!is_dir($folder)) {
            return;
        }

        $entries = scandir($folder);
        foreach (array_diff($entries === false ? [] : $entries, ['.', '..']) as $entry) {
            self::remove($folder . DIRECTORY_SEPARATOR . $entry);
        }
        rmdir($folder);
    }
}
