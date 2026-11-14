<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Identity;

final class SessionId
{
    private function __construct() {}

    public static function newlyGenerated(): string
    {
        $randomBytes = random_bytes(16);
        $randomBytes[6] = chr(ord($randomBytes[6]) & 0x0F | 0x40);
        $randomBytes[8] = chr(ord($randomBytes[8]) & 0x3F | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($randomBytes), 4));
    }
}
