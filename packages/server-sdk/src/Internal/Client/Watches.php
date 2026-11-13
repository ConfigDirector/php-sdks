<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Client;

final class Watches
{
    /** @var array<array-key, list<Watch>> */
    private array $watchesByConfigKey = [];

    public function add(Watch $watch): void
    {
        $this->watchesByConfigKey[$watch->configKey][] = $watch;
    }

    public function remove(Watch $watch): void
    {
        $this->watchesByConfigKey[$watch->configKey] = array_values(array_filter(
            $this->watchesByConfigKey[$watch->configKey] ?? [],
            static fn(Watch $other): bool => $other !== $watch,
        ));
    }

    public function removeFirstWithCallback(string $configKey, callable $callback): void
    {
        foreach ($this->watchesByConfigKey[$configKey] ?? [] as $watch) {
            if ($watch->hasCallback($callback)) {
                $this->remove($watch);

                return;
            }
        }
    }

    public function removeAllOn(string $configKey): void
    {
        unset($this->watchesByConfigKey[$configKey]);
    }

    public function removeAll(): void
    {
        $this->watchesByConfigKey = [];
    }

    /**
     * @param list<string> $configKeys
     *
     * @return list<Watch>
     */
    public function on(array $configKeys): array
    {
        $watches = [];
        foreach ($configKeys as $configKey) {
            array_push($watches, ...$this->watchesByConfigKey[$configKey] ?? []);
        }

        return $watches;
    }
}
