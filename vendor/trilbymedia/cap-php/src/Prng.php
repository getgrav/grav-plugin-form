<?php

declare(strict_types=1);

namespace TrilbyMedia\Cap;

/**
 * Deterministic PRNG that must be bit-exact with the upstream cap.js
 * implementation in server/index.js. Used to regenerate the salt/target
 * pairs for each sub-challenge from the challenge token.
 *
 * JS uses 32-bit unsigned/int32 semantics. In PHP (64-bit) we emulate
 * that with explicit `& 0xFFFFFFFF` masks after every arithmetic op.
 */
final class Prng
{
    private const UINT32_MASK = 0xFFFFFFFF;
    private const FNV_OFFSET  = 0x811C9DC5; // 2166136261

    public static function generate(string $seed, int $length): string
    {
        if ($length <= 0) {
            return '';
        }

        $state  = self::fnv1a($seed);
        $result = '';

        while (strlen($result) < $length) {
            $state = self::next($state);
            $result .= str_pad(dechex($state), 8, '0', STR_PAD_LEFT);
        }

        return substr($result, 0, $length);
    }

    private static function fnv1a(string $str): int
    {
        $hash = self::FNV_OFFSET;
        $len  = strlen($str);

        for ($i = 0; $i < $len; $i++) {
            $hash = ($hash ^ ord($str[$i])) & self::UINT32_MASK;

            $s1  = ($hash << 1)  & self::UINT32_MASK;
            $s4  = ($hash << 4)  & self::UINT32_MASK;
            $s7  = ($hash << 7)  & self::UINT32_MASK;
            $s8  = ($hash << 8)  & self::UINT32_MASK;
            $s24 = ($hash << 24) & self::UINT32_MASK;

            $hash = ($hash + $s1 + $s4 + $s7 + $s8 + $s24) & self::UINT32_MASK;
        }

        return $hash;
    }

    private static function next(int $state): int
    {
        $state = ($state ^ (($state << 13) & self::UINT32_MASK)) & self::UINT32_MASK;
        $state = ($state ^ ($state >> 17)) & self::UINT32_MASK;
        $state = ($state ^ (($state << 5) & self::UINT32_MASK)) & self::UINT32_MASK;

        return $state;
    }
}
