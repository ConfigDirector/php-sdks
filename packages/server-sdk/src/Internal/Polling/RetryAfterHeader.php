<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Polling;

final class RetryAfterHeader
{
    private const float MOST_SECONDS_READ = 1_000_000_000.0;

    private function __construct() {}

    public static function valueIn(string $headerLine): ?string
    {
        [$name, $value] = [...explode(':', $headerLine, 2), ''];

        return strcasecmp(trim($name), 'Retry-After') === 0 ? trim($value) : null;
    }

    public static function secondsNamedBy(string $headerValue, int $nowEpochMilliseconds): ?int
    {
        if (ctype_digit($headerValue)) {
            return (int) min((float) $headerValue, self::MOST_SECONDS_READ);
        }

        $date = \DateTimeImmutable::createFromFormat('!D, d M Y H:i:s \G\M\T', $headerValue, new \DateTimeZone('UTC'));

        return $date === false ? null : $date->getTimestamp() - intdiv($nowEpochMilliseconds, 1_000);
    }
}
