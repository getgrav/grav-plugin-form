<?php

declare(strict_types=1);

namespace TrilbyMedia\Cap\Instrumentation;

/** JS-compatible bound-data encoding. No mbstring or zlib requirement. @internal */
final class Encoding
{
    public static function hash(string $value, int $seed): int
    {
        // JS hashes UTF-16 code units, including surrogate pairs for non-BMP characters.
        $units = [];
        $len = strlen($value);
        for ($i = 0; $i < $len; $i++) {
            $c = ord($value[$i]);
            if ($c >= 240) {
                $c = (($c & 7) << 18) | ((ord($value[++$i]) & 63) << 12) | ((ord($value[++$i]) & 63) << 6) | (ord($value[++$i]) & 63);
                $units[] = 0xD800 + (($c - 0x10000) >> 10);
                $c = 0xDC00 + (($c - 0x10000) & 1023);
            } elseif ($c >= 224) {
                $c = (($c & 15) << 12) | ((ord($value[++$i]) & 63) << 6) | (ord($value[++$i]) & 63);
            } elseif ($c >= 192) {
                $c = (($c & 31) << 6) | (ord($value[++$i]) & 63);
            }
            $units[] = $c;
        }
        $h = $seed & 0xFFFFFFFF;
        foreach ($units as $c) {
            $h ^= $c;
            $h = ($h + ($h << 1) + ($h << 4) + ($h << 7) + ($h << 8) + ($h << 24)) & 0xFFFFFFFF;
        }
        return $h;
    }

    public static function json(mixed $value): string
    {
        $precision = ini_get('serialize_precision');
        try {
            if ($precision !== '-1') ini_set('serialize_precision', '-1');
            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR);
        } finally {
            if ($precision !== '-1') ini_set('serialize_precision', $precision);
        }
    }

    public static function utf16Length(string $value): int
    {
        $units = 0;
        for ($i = 0, $len = strlen($value); $i < $len; $i++) {
            $byte = ord($value[$i]);
            if ($byte < 128 || $byte >= 192) $units += $byte >= 240 ? 2 : 1;
        }
        return $units;
    }

    public static function canonical(mixed $value): string
    {
        if (is_object($value)) {
            $fields = get_object_vars($value);
            ksort($fields, SORT_STRING);
            $parts = [];
            foreach ($fields as $k => $v) {
                if (in_array($k, ['__proto__', 'constructor', 'prototype'], true)) {
                    throw new \InvalidArgumentException('Unsafe bound-data key');
                }
                $parts[] = self::json((string)$k) . ':' . self::canonical($v);
            }
            return '{' . implode(',', $parts) . '}';
        }
        if (is_array($value)) {
            return '[' . implode(',', array_map([self::class, 'canonical'], $value)) . ']';
        }
        $json = self::json($value);
        if (is_float($value)) {
            if ($value == 0) {
                return '0';
            }
            $json = preg_replace('/\.0e/', 'e', $json);
            if (str_contains($json, 'e')) {
                [$mantissa, $exponent] = explode('e', $json);
                $exp = (int)$exponent;
                if ($exp >= -6 && $exp < 21) {
                    $negative = $mantissa[0] === '-';
                    $digits = str_replace(['-', '.'], '', $mantissa);
                    $point = strpos(ltrim($mantissa, '-'), '.');
                    $point = ($point === false ? strlen($digits) : $point) + $exp;
                    $json = $point <= 0 ? '0.' . str_repeat('0', -$point) . $digits
                        : ($point >= strlen($digits) ? $digits . str_repeat('0', $point - strlen($digits)) : substr($digits, 0, $point) . '.' . substr($digits, $point));
                    return ($negative ? '-' : '') . $json;
                }
                return $mantissa . 'e' . ($exp >= 0 ? '+' : '') . $exp;
            }
        }
        return $json;
    }

    public static function deflate(string $script): string
    {
        if (function_exists('gzdeflate')) {
            return gzdeflate($script, 1);
        }
        return self::storedDeflate($script);
    }

    /** RFC 1951 stored blocks: valid raw DEFLATE without ext-zlib. */
    public static function storedDeflate(string $script): string
    {
        $out = '';
        $chunks = $script === '' ? [''] : str_split($script, 65535);
        foreach ($chunks as $i => $chunk) {
            $len = strlen($chunk);
            $out .= chr($i === count($chunks) - 1 ? 1 : 0) . pack('vv', $len, $len ^ 0xFFFF) . $chunk;
        }
        return $out;
    }
}
