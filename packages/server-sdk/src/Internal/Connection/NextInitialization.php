<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Connection;

enum NextInitialization
{
    case ServesTheValues;
    case IsHeld;
    case Fails;
}
