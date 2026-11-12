<?php

declare(strict_types=1);

namespace ConfigDirector\Tests\Support;

use Psr\Log\AbstractLogger;

final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, context: array<array-key, mixed>}> */
    private array $records = [];

    /**
     * @param array<array-key, mixed> $context
     */
    #[\Override]
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'context' => $context];
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    public function contextsLoggedAt(string $level): array
    {
        $contexts = [];
        foreach ($this->records as $record) {
            if ($record['level'] === $level) {
                $contexts[] = $record['context'];
            }
        }

        return $contexts;
    }
}
