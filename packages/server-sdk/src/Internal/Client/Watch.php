<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Client;

use ConfigDirector\Context;
use ConfigDirector\Internal\Value\InCodeDefaultValue;

final readonly class Watch
{
    /** @var callable */
    private mixed $callback;

    public function __construct(
        public string $configKey,
        public InCodeDefaultValue $inCodeDefaultValue,
        callable $callback,
        public ?Context $context,
    ) {
        $this->callback = $callback;
    }

    public function hasCallback(callable $callback): bool
    {
        return $this->callback === $callback;
    }

    /**
     * @param bool|string|int|float|array<array-key, mixed> $value
     */
    public function receive(bool|string|int|float|array $value): void
    {
        ($this->callback)($value);
    }
}
