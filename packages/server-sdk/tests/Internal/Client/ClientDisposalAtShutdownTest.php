<?php

declare(strict_types=1);

namespace ConfigDirector\Tests\Internal\Client;

use ConfigDirector\Tests\Support\PhpProcess;
use PHPUnit\Framework\TestCase;

final class ClientDisposalAtShutdownTest extends TestCase
{
    public function testAClientLeftUndisposedIsDisposedAfterTheShutdownFunctionsOfTheApplication(): void
    {
        $output = PhpProcess::running(
            __DIR__ . '/Processes/leave-a-client-undisposed.php',
            ['end-of-script'],
        )->finished()->output;

        self::assertSame(
            "output-of-the-application\nready-in-the-shutdown-function-of-the-application\nconnection-closed\n",
            $output,
        );
    }

    public function testAClientLeftUndisposedIsDisposedWhenTheScriptEndsOnAFatalError(): void
    {
        $output = PhpProcess::running(
            __DIR__ . '/Processes/leave-a-client-undisposed.php',
            ['fatal-error'],
            ['display_errors' => '0', 'log_errors' => '0'],
        )->finished()->output;

        self::assertSame(
            "output-of-the-application\nready-in-the-shutdown-function-of-the-application\nconnection-closed\n",
            $output,
        );
    }

    public function testAClientLeftUndisposedIsDisposedWhenTheScriptEndsOnAnUncaughtException(): void
    {
        $output = PhpProcess::running(
            __DIR__ . '/Processes/leave-a-client-undisposed.php',
            ['uncaught-exception'],
            ['display_errors' => '0', 'log_errors' => '0'],
        )->finished()->output;

        self::assertSame(
            "output-of-the-application\nready-in-the-shutdown-function-of-the-application\nconnection-closed\n",
            $output,
        );
    }
}
