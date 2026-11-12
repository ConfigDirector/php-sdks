<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Value;

enum InCodeDefaultValueType
{
    case Boolean;
    case String;
    case Integer;
    case Float;
    case Array;
}
