<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\ConfigDirectorSettings;
use ConfigDirector\ConnectionOptions;

final class SdkServerOfTheSampleTests
{
    /**
     * @param resource $process
     * @param resource $standardOutput
     */
    private function __construct(
        private readonly mixed $process,
        private readonly mixed $standardOutput,
        private readonly string $url,
        private readonly string $storagePath,
    ) {}

    public static function start(): self
    {
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/serve-the-sample-configs.php'],
            [1 => ['pipe', 'w'], 2 => STDERR],
            $pipes,
        );
        $address = $process === false ? false : fgets($pipes[1]);
        if ($process === false || $address === false) {
            throw new \RuntimeException('The SDK server of the sample tests did not start.');
        }

        $storagePath = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR . 'configdirector-slim-sample-' . bin2hex(random_bytes(8));
        mkdir($storagePath, 0o700);

        return new self($process, $pipes[1], 'http://' . trim($address), $storagePath);
    }

    public function settings(?string $logLevel = null): ConfigDirectorSettings
    {
        return new ConfigDirectorSettings(
            serverSdkKey: 'fake-sample-key',
            connection: new ConnectionOptions(url: $this->url, storagePath: $this->storagePath),
            logLevel: $logLevel,
        );
    }

    public function stop(): void
    {
        proc_terminate($this->process);
        fclose($this->standardOutput);
        proc_close($this->process);
        self::remove($this->storagePath);
    }

    private static function remove(string $folder): void
    {
        foreach (array_diff(scandir($folder) ?: [], ['.', '..']) as $entry) {
            $path = $folder . DIRECTORY_SEPARATOR . $entry;
            is_dir($path) ? self::remove($path) : unlink($path);
        }
        rmdir($folder);
    }
}
