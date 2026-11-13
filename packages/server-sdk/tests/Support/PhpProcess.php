<?php

declare(strict_types=1);

namespace ConfigDirector\Tests\Support;

use Composer\Autoload\ClassLoader;

final class PhpProcess
{
    /**
     * @param resource $process
     * @param resource $standardOutput
     */
    private function __construct(
        private readonly mixed $process,
        private readonly mixed $standardOutput,
    ) {}

    /**
     * @param list<string> $arguments
     * @param array<string, string> $phpSettings
     * @param list<string> $commandPrefix
     */
    public static function running(
        string $script,
        array $arguments,
        array $phpSettings = [],
        array $commandPrefix = [],
    ): self {
        $phpOptions = [];
        foreach ($phpSettings as $setting => $value) {
            array_push($phpOptions, '-d', "{$setting}=\"{$value}\"");
        }

        $process = proc_open(
            [...$commandPrefix, PHP_BINARY, ...$phpOptions, $script, self::autoloadFile(), ...$arguments],
            [1 => ['pipe', 'w'], 2 => STDERR],
            $pipes,
        );
        if ($process === false) {
            throw new \RuntimeException("The PHP process for {$script} could not be started.");
        }

        return new self($process, $pipes[1]);
    }

    public function isRunning(): bool
    {
        return proc_get_status($this->process)['running'];
    }

    public function nextLineOfOutput(): string
    {
        $line = fgets($this->standardOutput);

        return $line === false ? '' : rtrim($line, "\r\n");
    }

    public function finished(): FinishedPhpProcess
    {
        $output = stream_get_contents($this->standardOutput);
        fclose($this->standardOutput);
        $status = proc_get_status($this->process);
        while ($status['running']) {
            usleep(1_000);
            $status = proc_get_status($this->process);
        }
        proc_close($this->process);

        return new FinishedPhpProcess(
            $output === false ? '' : $output,
            $status['signaled'] ? $status['termsig'] : null,
        );
    }

    public function stop(): void
    {
        proc_terminate($this->process);
        fclose($this->standardOutput);
        proc_close($this->process);
    }

    public static function autoloadFile(): string
    {
        $classLoaderFile = (new \ReflectionClass(ClassLoader::class))->getFileName();
        if ($classLoaderFile === false) {
            throw new \RuntimeException('The Composer autoloader could not be located.');
        }

        return dirname($classLoaderFile, 2) . DIRECTORY_SEPARATOR . 'autoload.php';
    }
}
