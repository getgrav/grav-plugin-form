<?php

declare(strict_types=1);

namespace TrilbyMedia\Cap\Instrumentation;

/** Bound instrumentation verification from capjs-core 0.1.11. @internal */
final class Verifier
{
    public static function boundValues(array $binding, string $serialized): array
    {
        $g = [Encoding::hash($serialized, $binding['hs'][0]), Encoding::hash($serialized, $binding['hs'][1])];
        $out = [];
        foreach ($binding['pv'] as $i => $v) {
            $out[] = (($v ^ $binding['sl'][$i] ^ ($i === $binding['j'][0] ? $g[0] : 0) ^ ($i === $binding['j'][1] ? $g[1] : 0)) & 0x7FFFFFFF) % 900_000 + 100_000;
        }
        return $out;
    }

    public static function verify(array $meta, mixed $payload, int $now): array
    {
        $fail = static fn (string $reason) => ['valid' => false, 'reason' => $reason];
        try {
            if (!is_array($payload) || array_is_list($payload)) {
                return $fail('missing_output');
            }
            if (($payload['i'] ?? null) !== $meta['id']) {
                return $fail('id_mismatch');
            }
            $state = $payload['state'] ?? null;
            if (!is_array($state) || array_is_list($state)) {
                return $fail('invalid_state');
            }
            $vars = $meta['vars'];
            if (array_diff(array_keys($payload), ['i', 'state', 'd', 'vp', 'p', 'ts']) || count($state) !== count($vars)) {
                return $fail('failed_challenge');
            }
            $serialized = $payload['d'] ?? null;
            if (!is_string($serialized)) {
                return $fail('failed_challenge');
            }
            if (strlen($serialized) > 262_144 || Encoding::utf16Length($serialized) > 65_536) {
                return $fail('instr_oversized');
            }
            $parsed = json_decode($serialized, false, 128, JSON_THROW_ON_ERROR);
            if (!$parsed instanceof \stdClass || Encoding::canonical($parsed) !== $serialized || !self::validData($parsed)) {
                return $fail('failed_challenge');
            }
            $expected = self::boundValues($meta['binding'], $serialized);
            $actual = [];
            foreach ($vars as $v) {
                if (!isset($state[$v]) || !is_int($state[$v])) {
                    return $fail('failed_challenge');
                }
                $actual[] = $state[$v];
            }
            if (!hash_equals(Encoding::json($expected), Encoding::json($actual))) {
                return $fail('failed_challenge');
            }
            $d = json_decode($serialized, true, 128, JSON_THROW_ON_ERROR);
            if ($meta['iat'] + $d['el'] > $now + 5000 || $d['el'] > $meta['expires'] - $meta['iat'] + 5000) {
                return $fail('instr_stale');
            }
            $legacy = ($d['lw'] ?? null) === 1;
            $isolated = ($d['is'] ?? null) === 1 && !$legacy;
            $reduced = $legacy || $isolated;
            $unreachable = $reduced ? ($isolated ? 'instr_isolated' : 'instr_widget_outdated') : 'instr_host_unreachable';
            if ($meta['behavior'] && $d['bh'] === null && (!$reduced || $meta['behaviorEnforced'])) {
                return $fail($reduced ? $unreachable : 'behavior_missing');
            }
            if ($d['hp'] === null && $meta['blockAutomatedBrowsers']) {
                return $fail($unreachable);
            }
            if ($d['ia'] === null && ($reduced ? $meta['blockAutomatedInteractions'] : $meta['detectAutomatedInteractions'])) {
                return $fail($unreachable);
            }
            $risk = [];
            if ($d['hp'] === null) {
                $risk[] = 'host_unreachable';
            }
            if ($legacy) {
                $risk[] = 'legacy_widget';
            }
            if ($isolated) {
                $risk[] = 'isolated_frame';
            }
            if (($d['ce'] ?? 0) > 0) {
                $risk[] = 'csp_eval_blocked';
            }
            $interaction = [];
            if ($meta['detectAutomatedInteractions'] && $d['ia'] !== null) {
                if ($d['ia'][0] >= 3) {
                    $interaction[] = 'interaction_synthetic_clicks';
                }
                if ($d['ia'][1] >= 3) {
                    $interaction[] = 'interaction_synthetic_keydowns';
                }
            }
            if ($meta['blockAutomatedInteractions'] && $interaction) {
                return $fail('instr_automated_interaction') + ['blockedBy' => $interaction];
            }
            $boundFlags = in_array('automation', $d['ag'], true) ? ['automation_globals'] : [];
            $detected = ['pass' => true, 'riskFlags' => [], 'blockedBy' => []];
            if ($d['p'] !== null) {
                $vector = $d['p'];
                if ($d['vp'] !== null) {
                    $vector['innerWH'] = $d['vp'];
                }
                $vector['cdp'] = ['trap' => in_array('cdp_trap', $d['ag'], true) || ($d['hp']['cdp'] ?? null) === true, 'shadow' => !in_array('shadow_patched', $d['ag'], true)];
                if ($d['hp'] !== null) {
                    $vector['host'] = $d['hp'];
                }
                $detected = Detector::detect($vector, $parsed->p);
                if ($meta['blockAutomatedBrowsers'] && !$detected['pass']) {
                    return $fail('instr_automated_browser') + ['blockedBy' => $detected['blockedBy']];
                }
            } elseif ($meta['blockAutomatedBrowsers']) {
                return $fail('instr_probe_missing');
            }
            $brands = ($d['hp']['nav']['brands'] ?? '') . ',' . ($d['p']['mainNav']['brands'] ?? '');
            return [
                'valid' => true,
                'riskFlags' => array_merge($detected['riskFlags'], $interaction, $boundFlags, $risk),
                'blockedBy' => $detected['blockedBy'],
                'trace' => $d['bh'],
                'agent' => ['all' => $d['ag'], 'host' => $d['hp'] !== null, 'legacy' => $legacy, 'isolated' => $isolated, 'browser' => ($d['p']['mainNav']['brave'] ?? null) === true || preg_match('/(^|,)Brave\//', $brands) ? 'brave' : (preg_match('/(^|,)Google Chrome\//', $brands) ? 'chrome' : null)],
            ];
        } catch (\Throwable $e) {
            return $fail('failed_challenge');
        }
    }

    private static function validData(\stdClass $d): bool
    {
        if (array_diff(array_keys(get_object_vars($d)), ['v', 'el', 'p', 'vp', 'hp', 'ag', 'ia', 'bh', 'ce', 'lw', 'is'])) {
            return false;
        }
        if (($d->v ?? null) !== 1 || !is_int($d->el ?? null) || $d->el < 0 || !self::codes($d->ag ?? null)) {
            return false;
        }
        foreach (['p', 'hp', 'bh'] as $field) {
            if (!property_exists($d, $field) || ($d->$field !== null && !$d->$field instanceof \stdClass)) {
                return false;
            }
        }
        if (!property_exists($d, 'vp') || ($d->vp !== null && !(is_array($d->vp) && count($d->vp) === 2 && self::num($d->vp[0]) && self::num($d->vp[1])))) {
            return false;
        }
        if (property_exists($d, 'ce') && !in_array($d->ce, [0, 1, 2], true)) {
            return false;
        }
        foreach (['lw', 'is'] as $key) {
            if (property_exists($d, $key) && ($d->$key !== 1 || ($d->ia ?? null) !== null || $d->bh !== null)) {
                return false;
            }
        }
        if (!property_exists($d, 'ia') || ($d->ia !== null && !(is_array($d->ia) && count($d->ia) === 2 && is_int($d->ia[0]) && is_int($d->ia[1]) && min($d->ia) >= 0 && max($d->ia) <= 255))) {
            return false;
        }
        foreach ([$d->hp->nav->brands ?? null, $d->p->mainNav->brands ?? null, $d->p->ua ?? null] as $s) {
            if ($s !== null && !is_string($s)) {
                return false;
            }
        }
        $p = $d->p;
        if (isset($p->webdriver) && !is_bool($p->webdriver)) {
            return false;
        }
        if (isset($p->uaData->brands) && (!is_array($p->uaData->brands) || count(array_filter($p->uaData->brands, 'is_string')) !== count($p->uaData->brands))) {
            return false;
        }
        if (isset($p->langs) && (!is_array($p->langs) || !self::num($p->langs[0] ?? null) || !is_bool($p->langs[1] ?? null))) {
            return false;
        }
        return true;
    }

    private static function num(mixed $v): bool
    {
        return (is_int($v) || is_float($v)) && is_finite((float)$v);
    }

    private static function codes(mixed $list): bool
    {
        if (!is_array($list) || !array_is_list($list) || count($list) > 32) {
            return false;
        }
        foreach ($list as $i => $s) {
            if (!is_string($s) || !preg_match('/^[a-z_]{1,24}$/D', $s) || ($i > 0 && strcmp($list[$i - 1], $s) >= 0)) {
                return false;
            }
        }
        return true;
    }
}
