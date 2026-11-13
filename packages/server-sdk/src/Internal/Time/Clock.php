<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Time;

interface Clock
{
    public function epochMilliseconds(): int;

    public function monotonicMilliseconds(): int;
}
