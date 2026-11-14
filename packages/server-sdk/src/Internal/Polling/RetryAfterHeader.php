<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Polling;

final readonly class RetryAfterHeader
{
    private const float MOST_SECONDS_READ = 1_000_000_000.0;

    private function __construct(private string $value) {}

    public static function in(string $headerLine): ?self
    {
        [$name, $value] = [...explode(':', $headerLine, 2), ''];

        return strcasecmp(trim($name), 'Retry-After') === 0 ? new self(trim($value)) : null;
    }

    public function secondsToWaitFrom(int $nowEpochMilliseconds): ?int
    {
        $isANumberOfSeconds = $this->value !== '' && strspn($this->value, '0123456789') === strlen($this->value);
        if ($isANumberOfSeconds) {
            return (int) min((float) $this->value, self::MOST_SECONDS_READ);
        }
        if (str_contains($this->value, "\0")) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!D, d M Y H:i:s \G\M\T', $this->value, new \DateTimeZone('UTC'));

        return $date === false ? null : $date->getTimestamp() - intdiv($nowEpochMilliseconds, 1_000);
    }
}
