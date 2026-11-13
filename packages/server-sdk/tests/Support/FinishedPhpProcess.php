<?php

declare(strict_types=1);

namespace ConfigDirector\Tests\Support;

final readonly class FinishedPhpProcess
{
    public function __construct(
        public string $output,
        public ?int $terminatingSignal,
    ) {}
}
