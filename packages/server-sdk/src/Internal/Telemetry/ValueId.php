<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Telemetry;

final class ValueId
{
    private const int DIGEST_BYTES = 16;

    private const int LENGTH = 22;

    private const string BASE62_DIGITS = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    private function __construct() {}

    public static function of(string $valueText): string
    {
        $numberAsBytes = array_map(
            ord(...),
            str_split(substr(hash('sha256', $valueText, true), 0, self::DIGEST_BYTES)),
        );
        $base62Text = '';
        while ($numberAsBytes !== []) {
            $remainder = 0;
            $quotientAsBytes = [];
            foreach ($numberAsBytes as $byte) {
                $dividend = $remainder * 256 + $byte;
                $remainder = $dividend % 62;
                $quotientByte = intdiv($dividend, 62);
                if ($quotientAsBytes !== [] || $quotientByte !== 0) {
                    $quotientAsBytes[] = $quotientByte;
                }
            }
            $base62Text = self::BASE62_DIGITS[$remainder] . $base62Text;
            $numberAsBytes = $quotientAsBytes;
        }

        return str_pad($base62Text, self::LENGTH, '0', STR_PAD_LEFT);
    }
}
