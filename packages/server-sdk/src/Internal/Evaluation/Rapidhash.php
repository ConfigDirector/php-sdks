<?php

declare(strict_types=1);

namespace ConfigDirector\Internal\Evaluation;

final class Rapidhash
{
    private const array SECRET = [
        0x2D358DCC << 32 | 0xAA6C78A5,
        0x8BB84B93 << 32 | 0x962EACC9,
        0x4B33A62E << 32 | 0xD433D4A3,
        0x4D5A2DA5 << 32 | 0x1DE1AA47,
        0xA0761D64 << 32 | 0x78BD642F,
        0xE7037ED1 << 32 | 0xA0B428DB,
        0x90ED1765 << 32 | 0x281C388C,
        0xAAAAAAAA << 32 | 0xAAAAAAAA,
    ];

    private const int BLOCK_LENGTH = 112;

    private const array REMAINDER_PAIR_SECRETS = [2, 2, 1, 1, 2, 1];

    private function __construct() {}

    public static function hash(string $data, int $seed): int
    {
        $length = strlen($data);
        $seed ^= self::mix($seed ^ self::SECRET[2], self::SECRET[1]);

        if ($length <= 16) {
            [$a, $b] = self::shortInput($data, $length);
            if ($length >= 4) {
                $seed ^= $length;
            }
            $lastBlockLength = $length;
        } else {
            [$remainderOffset, $seed] = self::blocks($data, $length, $seed);
            $seed = self::remainderPairs($data, $remainderOffset, $length, $seed);
            $lastBlockLength = $length - $remainderOffset;
            $a = self::read64($data, $length - 16) ^ $lastBlockLength;
            $b = self::read64($data, $length - 8);
        }

        return self::epilogue($a ^ self::SECRET[1], $b ^ $seed, $lastBlockLength);
    }

    /**
     * @return array{int, int}
     */
    private static function shortInput(string $data, int $length): array
    {
        if ($length >= 8) {
            return [self::read64($data, 0), self::read64($data, $length - 8)];
        }
        if ($length >= 4) {
            return [self::read32($data, 0), self::read32($data, $length - 4)];
        }
        if ($length > 0) {
            $first = ord($data[0]);
            $a = ord($data[$length - 1]) | ((($first << 5) & 0xFF) << 40) | (($first >> 3) << 48);

            return [$a, ord($data[$length >> 1])];
        }

        return [0, 0];
    }

    /**
     * @return array{int, int}
     */
    private static function blocks(string $data, int $length, int $seed): array
    {
        if ($length <= self::BLOCK_LENGTH) {
            return [0, $seed];
        }

        $lanes = array_fill(0, 7, $seed);
        $offset = 0;
        do {
            foreach ($lanes as $lane => $laneSeed) {
                $laneOffset = $offset + $lane * 16;
                $lanes[$lane] = self::mix(
                    self::read64($data, $laneOffset) ^ self::SECRET[$lane],
                    self::read64($data, $laneOffset + 8) ^ $laneSeed,
                );
            }
            $offset += self::BLOCK_LENGTH;
        } while ($length - $offset > self::BLOCK_LENGTH);

        return [$offset, array_reduce($lanes, static fn(int $folded, int $lane): int => $folded ^ $lane, 0)];
    }

    private static function remainderPairs(string $data, int $offset, int $length, int $seed): int
    {
        foreach (self::REMAINDER_PAIR_SECRETS as $pair => $secret) {
            $pairOffset = $offset + $pair * 16;
            if ($length - $pairOffset <= 16) {
                break;
            }
            $seed = self::mix(
                self::read64($data, $pairOffset) ^ self::SECRET[$secret],
                self::read64($data, $pairOffset + 8) ^ $seed,
            );
        }

        return $seed;
    }

    private static function mix(int $a, int $b): int
    {
        [$high, $low] = self::multiply($a, $b);

        return $low ^ $high;
    }

    private static function epilogue(int $a, int $b, int $lastBlockLength): int
    {
        [$high, $low] = self::multiply($a, $b);

        return self::mix($low ^ self::SECRET[7], $high ^ self::SECRET[1] ^ $lastBlockLength);
    }

    /**
     * @return array{int, int}
     */
    private static function multiply(int $a, int $b): array
    {
        $aLimbs = self::sixteenBitLimbs($a);
        $bLimbs = self::sixteenBitLimbs($b);

        $productLimbs = [];
        $carry = 0;
        for ($column = 0; $column < 8; $column++) {
            $sum = $carry;
            for ($aIndex = max(0, $column - 3); $aIndex <= min(3, $column); $aIndex++) {
                $sum += $aLimbs[$aIndex] * $bLimbs[$column - $aIndex];
            }
            $productLimbs[] = $sum & 0xFFFF;
            $carry = $sum >> 16;
        }

        return [
            $productLimbs[4] | $productLimbs[5] << 16 | $productLimbs[6] << 32 | $productLimbs[7] << 48,
            $productLimbs[0] | $productLimbs[1] << 16 | $productLimbs[2] << 32 | $productLimbs[3] << 48,
        ];
    }

    /**
     * @return array{int, int, int, int}
     */
    private static function sixteenBitLimbs(int $value): array
    {
        return [$value & 0xFFFF, ($value >> 16) & 0xFFFF, ($value >> 32) & 0xFFFF, ($value >> 48) & 0xFFFF];
    }

    private static function read64(string $data, int $offset): int
    {
        return self::read32($data, $offset) | self::read32($data, $offset + 4) << 32;
    }

    private static function read32(string $data, int $offset): int
    {
        return ord($data[$offset])
            | ord($data[$offset + 1]) << 8
            | ord($data[$offset + 2]) << 16
            | ord($data[$offset + 3]) << 24;
    }
}
