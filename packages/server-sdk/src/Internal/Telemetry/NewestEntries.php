<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Telemetry;

/**
 * @template TEntry
 */
final class NewestEntries
{
    /** @var array<string, TEntry> */
    private array $entriesByKey = [];

    /** @var \SplQueue<string> */
    private \SplQueue $keysOldestFirst;

    public function __construct(private readonly int $limit)
    {
        $this->keysOldestFirst = new \SplQueue();
    }

    /**
     * @return TEntry|null
     */
    public function entryWithKey(string $key): mixed
    {
        return $this->entriesByKey[$key] ?? null;
    }

    /**
     * @param TEntry $entry
     *
     * @return TEntry|null
     */
    public function oldestEntryLeftOutByPutting(string $key, mixed $entry): mixed
    {
        if (!array_key_exists($key, $this->entriesByKey)) {
            $this->keysOldestFirst->enqueue($key);
        }
        $this->entriesByKey[$key] = $entry;
        if (count($this->entriesByKey) <= $this->limit) {
            return null;
        }

        $oldestKey = $this->keysOldestFirst->dequeue();
        $oldestEntry = $this->entriesByKey[$oldestKey];
        unset($this->entriesByKey[$oldestKey]);

        return $oldestEntry;
    }

    /**
     * @return array<string, TEntry>
     */
    public function entriesByKey(): array
    {
        return $this->entriesByKey;
    }
}
