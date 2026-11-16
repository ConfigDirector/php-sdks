<?php

declare(strict_types=1);

namespace ConfigDirector\OpenFeature\Tests\Support;

use Composer\Autoload\ClassLoader;

final class PhpProcess
{
    private function __construct() {}

    /**
     * @param list<string> $arguments
     */
    public static function runToItsEnd(string $script, array $arguments): int
    {
        $process = proc_open(
            [PHP_BINARY, $script, self::autoloadFile(), ...$arguments],
            [1 => STDOUT, 2 => STDERR],
            $pipes,
        );
        if ($process === false) {
            throw new \RuntimeException("The PHP process for {$script} could not be started.");
        }

        return proc_close($process);
    }

    private static function autoloadFile(): string
    {
        $classLoaderFile = (new \ReflectionClass(ClassLoader::class))->getFileName();
        if ($classLoaderFile === false) {
            throw new \RuntimeException('The Composer autoloader could not be located.');
        }

        return dirname($classLoaderFile, 2) . DIRECTORY_SEPARATOR . 'autoload.php';
    }
}
