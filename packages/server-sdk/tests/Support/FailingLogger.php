<?php

declare(strict_types=1);

namespace ConfigDirector\Tests\Support;

use Psr\Log\AbstractLogger;

final class FailingLogger extends AbstractLogger
{
    /**
     * @param array<array-key, mixed> $context
     */
    #[\Override]
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        throw new \RuntimeException('The logger could not write.');
    }
}
