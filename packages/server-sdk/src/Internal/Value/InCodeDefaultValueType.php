<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Value;

enum InCodeDefaultValueType: string
{
    case Boolean = 'bool';
    case String = 'string';
    case Integer = 'int';
    case Float = 'float';
    case Array = 'array';
}
