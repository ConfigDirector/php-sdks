<?php

declare(strict_types=1);

namespace ConfigDirector\Tests\Support;

final class TemporaryFolder
{
    public static function create(): string
    {
        $folder = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'configdirector-tests-' . bin2hex(random_bytes(8));
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

        chmod($folder, 0o700);
        foreach (self::entriesOf($folder) as $entry) {
            self::remove($folder . DIRECTORY_SEPARATOR . $entry);
        }
        rmdir($folder);
    }

    /**
     * @return list<string>
     */
    public static function entriesOf(string $folder): array
    {
        $entries = scandir($folder);

        return $entries === false ? [] : array_values(array_diff($entries, ['.', '..']));
    }
}
