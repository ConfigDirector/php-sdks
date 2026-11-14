<?php

declare(strict_types=1);

namespace Tests\Support;

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
     * @param array<string, string> $environment
     */
    public static function running(
        string $script,
        array $arguments,
        array $environment = [],
        ?string $fileForStandardError = null,
    ): self {
        $process = proc_open(
            [PHP_BINARY, $script, ...$arguments],
            [1 => ['pipe', 'w'], 2 => $fileForStandardError === null ? STDERR : ['file', $fileForStandardError, 'a']],
            $pipes,
            env_vars: $environment === [] ? null : [...getenv(), ...$environment],
        );
        if ($process === false) {
            throw new \RuntimeException("The PHP process for {$script} could not be started.");
        }

        return new self($process, $pipes[1]);
    }

    public function signal(int $signal): void
    {
        proc_terminate($this->process, $signal);
    }

    public function nextLineOfOutput(): string
    {
        $line = fgets($this->standardOutput);

        return $line === false ? '' : rtrim($line, "\r\n");
    }

    public function exitCodeWithinTenSeconds(): ?int
    {
        stream_set_blocking($this->standardOutput, false);
        $deadline = hrtime(true) + 10_000_000_000;
        $status = proc_get_status($this->process);
        while ($status['running'] && hrtime(true) < $deadline) {
            stream_get_contents($this->standardOutput);
            usleep(5_000);
            $status = proc_get_status($this->process);
        }
        $this->stop();

        return $status['running'] || $status['signaled'] ? null : $status['exitcode'];
    }

    public function stop(): void
    {
        proc_terminate($this->process);
        fclose($this->standardOutput);
        proc_close($this->process);
    }
}
