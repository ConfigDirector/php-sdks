<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\LocalStore;

enum AbsentPayload
{
    case FileMissing;
    case FileUnreadable;
}
