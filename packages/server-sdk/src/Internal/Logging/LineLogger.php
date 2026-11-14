<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Logging;

use Psr\Log\AbstractLogger;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LogLevel;

final class LineLogger extends AbstractLogger
{
    private const array SEVERITY_BY_LEVEL = [
        LogLevel::DEBUG => 0,
        LogLevel::INFO => 1,
        LogLevel::NOTICE => 2,
        LogLevel::WARNING => 3,
        LogLevel::ERROR => 4,
        LogLevel::CRITICAL => 5,
        LogLevel::ALERT => 6,
        LogLevel::EMERGENCY => 7,
    ];

    /**
     * @param \Closure(string $line): void $writeLine
     */
    private function __construct(
        private readonly int $lowestSeverityWritten,
        private readonly \Closure $writeLine,
    ) {}

    public static function writingThroughErrorLogFrom(string $lowestLevelWritten): ?self
    {
        return self::writingFrom($lowestLevelWritten, static function (string $line): void {
            error_log($line);
        });
    }

    public static function writingToStandardErrorFrom(string $lowestLevelWritten): ?self
    {
        return self::writingFrom($lowestLevelWritten, static function (string $line): void {
            fwrite(STDERR, $line . "\n");
        });
    }

    /**
     * @param \Closure(string $line): void $writeLine
     */
    private static function writingFrom(string $lowestLevelWritten, \Closure $writeLine): ?self
    {
        $lowestSeverityWritten = self::SEVERITY_BY_LEVEL[$lowestLevelWritten] ?? null;

        return $lowestSeverityWritten === null ? null : new self($lowestSeverityWritten, $writeLine);
    }

    /**
     * @param array<array-key, mixed> $context
     */
    #[\Override]
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $severity = is_string($level) ? self::SEVERITY_BY_LEVEL[$level] ?? null : null;
        if ($severity === null) {
            throw new InvalidArgumentException('The log level must be one of the levels of Psr\Log\LogLevel.');
        }
        if ($severity < $this->lowestSeverityWritten) {
            return;
        }

        ($this->writeLine)(sprintf(
            '[ConfigDirector:php-server-sdk] %s: %s%s',
            $level,
            self::withPlaceholdersFilled((string) $message, $context),
            self::exceptionTextOf($context['exception'] ?? null),
        ));
    }

    private static function exceptionTextOf(mixed $exception): string
    {
        return $exception instanceof \Throwable
            ? sprintf(' %s: %s', $exception::class, self::onOneLine($exception->getMessage()))
            : '';
    }

    /**
     * @param array<array-key, mixed> $context
     */
    private static function withPlaceholdersFilled(string $message, array $context): string
    {
        $textByPlaceholder = [];
        foreach ($context as $name => $value) {
            $textByPlaceholder['{' . $name . '}'] = self::onOneLine(self::textOf($value));
        }

        return strtr($message, $textByPlaceholder);
    }

    private static function textOf(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        $json = json_encode($value, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $json === false ? get_debug_type($value) : $json;
    }

    private static function onOneLine(string $text): string
    {
        return strtr($text, ["\r" => ' ', "\n" => ' ']);
    }
}
