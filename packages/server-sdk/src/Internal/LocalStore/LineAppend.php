<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\LocalStore;

enum LineAppend
{
    case Appended;
    case OverTheSizeLimit;
    case FileUnwritable;
}
