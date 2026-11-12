<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Logging;

use Psr\Log\AbstractLogger;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LogLevel;

final class ErrorLogLogger extends AbstractLogger
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

    private function __construct(private readonly int $lowestSeverityWritten) {}

    public static function writingFrom(string $lowestLevelWritten): ?self
    {
        $lowestSeverityWritten = self::SEVERITY_BY_LEVEL[$lowestLevelWritten] ?? null;

        return $lowestSeverityWritten === null ? null : new self($lowestSeverityWritten);
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

        error_log(sprintf(
            '[ConfigDirector:php-server-sdk] %s: %s',
            $level,
            self::withPlaceholdersFilled((string) $message, $context),
        ));
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
