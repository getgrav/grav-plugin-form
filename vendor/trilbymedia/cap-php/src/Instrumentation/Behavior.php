<?php

declare(strict_types=1);

namespace TrilbyMedia\Cap\Instrumentation;

/** Experimental behavior rules from capjs-core 0.1.11 behavior.js. @internal */
final class Behavior
{
    private const WEIGHTS = ['bh_malformed' => .9, 'bh_replayed' => .95, 'bh_untrusted_activation' => .9, 'bh_linear_path' => .85, 'bh_polynomial_path' => .85, 'bh_hidden_activation' => .85, 'bh_uniform_typing' => .75, 'bh_superhuman_typing' => .5, 'bh_activation_unseen' => .7, 'bh_too_fast' => .6, 'bh_screen_coords_leak' => .5, 'bh_warp_click' => .35, 'bh_instant_press' => .3, 'bh_center_hit' => .3, 'bh_instant_key' => .2, 'bh_idle_then_act' => .15, 'bh_touch_geometry' => .15, 'bh_unexplained_scroll' => .3];
    private const STRONG = ['bh_malformed', 'bh_replayed', 'bh_untrusted_activation', 'bh_linear_path', 'bh_polynomial_path', 'bh_hidden_activation', 'bh_uniform_typing', 'bh_superhuman_typing', 'bh_activation_unseen', 'bh_too_fast', 'bh_screen_coords_leak'];

    private static function range(mixed $v, float $min, float $max): bool
    {
        return (is_int($v) || is_float($v)) && is_finite((float)$v) && $v >= $min && $v <= $max;
    }

    public static function validate(mixed $t): ?string
    {
        if (!is_array($t) || ($t !== [] && array_is_list($t))) return 'type';
        if (($t['v'] ?? null) !== 1) return 'version';
        if (!self::range($t['res'] ?? null, 0, 1000)) return 'res';
        foreach (['now', 'vis', 'last', 'us'] as $f) {
            if (!self::range($t[$f] ?? null, -10000, 21_600_000)) return 'times';
        }
        if (!is_array($t['env'] ?? null) || !array_is_list($t['env']) || count($t['env']) !== 8) return 'env';
        foreach ($t['env'] as $v) {
            if (!self::range($v, -100000, 100000)) return 'env';
        }
        if (!is_array($t['n'] ?? null) || !array_is_list($t['n']) || !in_array(count($t['n']), [10, 11], true)) return 'counters';
        foreach ($t['n'] as $v) {
            if (!is_int($v) || $v < 0 || $v > 10_000_000) return 'counters';
        }
        if (!is_array($t['m'] ?? null) || !array_is_list($t['m']) || count($t['m']) > 320) return 'moves';
        $prev = -INF;
        foreach ($t['m'] as $p) {
            if (!is_array($p) || !array_is_list($p) || count($p) !== 5) return 'move';
            if (!self::range($p[0], -10000, 21_600_000) || !self::range($p[1], -100000, 100000) || !self::range($p[2], -100000, 100000) || !is_int($p[3]) || $p[3] < 0 || $p[3] > 1000 || !in_array($p[4], [0, 1, 2], true)) return 'move';
            if ($p[0] < $prev - 1000) return 'move_order';
            $prev = max($prev, $p[0]);
        }
        if (!is_array($t['k'] ?? null) || !array_is_list($t['k']) || count($t['k']) > 64) return 'keys';
        foreach ($t['k'] as $k) {
            if (!is_array($k) || !array_is_list($k) || count($k) !== 2 || !self::range($k[0], -10000, 21_600_000) || !self::range($k[1], -1, 60000)) return 'key';
        }
        if (!array_key_exists('a', $t)) return 'activation';
        if ($t['a'] !== null) {
            $a = $t['a'];
            if (!is_array($a) || array_is_list($a) || !in_array($a['k'] ?? null, [0, 1, 2], true) || !in_array($a['tr'] ?? null, [0, 1], true) || !in_array($a['hid'] ?? null, [0, 1], true) || !self::range($a['t'] ?? null, -10000, 21_600_000) || !self::range($a['u'] ?? null, -10000, 21_600_000)) return 'activation';
            if ($a['k'] === 0) {
                if (!in_array($a['pt'] ?? null, [0, 1, 2], true)) return 'activation';
                foreach (['x', 'y', 'cx', 'cy', 'w', 'h', 'sox', 'soy'] as $f) {
                    if (!self::range($a[$f] ?? null, -100000, 100000)) return 'activation';
                }
                if (!self::range($a['cw'] ?? null, 0, 10000) || !self::range($a['ch'] ?? null, 0, 10000) || !self::range($a['pr'] ?? null, 0, 1)) return 'activation';
            }
        }
        return null;
    }

    private static function cv(array $a): ?float
    {
        if (count($a) < 2) return null;
        $mean = array_sum($a) / count($a);
        if ($mean == 0) return 0.0;
        return sqrt(array_sum(array_map(static fn ($x) => ($x - $mean) ** 2, $a)) / count($a)) / abs($mean);
    }

    private static function median(array $a): ?float
    {
        if (!$a) return null;
        sort($a, SORT_NUMERIC);
        $mid = intdiv(count($a), 2);
        return count($a) % 2 ? (float)$a[$mid] : ($a[$mid - 1] + $a[$mid]) / 2;
    }

    private static function regular(array $a, float $tol): ?float
    {
        $median = self::median($a);
        return !$median ? null : count(array_filter($a, static fn ($x) => abs($x - $median) <= $tol * $median)) / count($a);
    }

    private static function rounded(?float $v, int $digits = 3): ?float
    {
        return $v === null ? null : round($v, $digits);
    }

    private static function typing(array $keys): ?array
    {
        if (count($keys) < 7) return null;
        $dd = [];
        for ($i = 1; $i < count($keys); $i++) {
            $delta = $keys[$i][0] - $keys[$i - 1][0];
            if ($delta > 0 && $delta < 2000) $dd[] = $delta;
        }
        $dwell = array_values(array_filter(array_column($keys, 1), static fn ($x) => $x >= 0));
        return ['keys' => count($keys), 'intervalMedian' => count($dd) >= 6 ? self::median($dd) : null, 'intervalCv' => count($dd) >= 7 ? self::cv($dd) : null, 'intervalRegular' => count($dd) >= 7 ? self::regular($dd, .08) : null, 'dwellCv' => count($dwell) >= 7 ? self::cv($dwell) : null, 'dwellRegular' => count($dwell) >= 7 ? self::regular($dwell, .12) : null, 'dwellMedian' => self::median($dwell)];
    }

    private static function diffs(array $run, int $axis, int $order): array
    {
        $d = array_column($run, $axis);
        for ($k = 0; $k < $order; $k++) {
            $next = [];
            for ($i = 1; $i < count($d); $i++) $next[] = $d[$i] - $d[$i - 1];
            $d = $next;
        }
        return $d;
    }

    private static function path(array $moves, array $a): ?array
    {
        $win = array_values(array_filter($moves, static fn ($p) => $p[4] === 0 && $p[0] <= $a['t'] && $p[0] >= $a['t'] - 1500));
        if (count($win) < 6) return null;
        $first = $win[0];
        $last = $win[count($win) - 1];
        $dx = $last[1] - $first[1];
        $dy = $last[2] - $first[2];
        $disp = hypot($dx, $dy);
        $steps = [];
        $maxDev = 0;
        for ($i = 1; $i < count($win); $i++) {
            $steps[] = hypot($win[$i][1] - $win[$i - 1][1], $win[$i][2] - $win[$i - 1][2]);
            if ($disp > 0) $maxDev = max($maxDev, abs($dy * $win[$i][1] - $dx * $win[$i][2] + $last[1] * $first[2] - $last[2] * $first[1]) / $disp);
        }
        $linear = false;
        $poly = false;
        for ($i = 0; $i + 8 <= count($win); $i++) {
            $run = array_slice($win, $i, 8);
            $moving = true;
            for ($j = 1; $j < 8; $j++) {
                if (hypot($run[$j][1] - $run[$j - 1][1], $run[$j][2] - $run[$j - 1][2]) < .5) $moving = false;
            }
            if (!$moving || hypot($run[7][1] - $run[0][1], $run[7][2] - $run[0][2]) < 60) continue;
            $flat = static fn ($v) => abs($v) <= .05;
            if (count(array_filter(self::diffs($run, 1, 2), $flat)) === 6 && count(array_filter(self::diffs($run, 2, 2), $flat)) === 6) {
                $linear = true;
                continue;
            }
            $onGrid = count(array_filter($run, static fn ($p) => floor($p[1] * 2) == $p[1] * 2 && floor($p[2] * 2) == $p[2] * 2)) === 8;
            $x = self::diffs($run, 1, 3);
            $y = self::diffs($run, 2, 3);
            if (!$onGrid && max($x) - min($x) <= .1 && max($y) - min($y) <= .1) $poly = true;
        }
        return ['samples' => count($win), 'displacement' => self::rounded($disp, 1), 'straightness' => self::rounded($disp > 0 ? array_sum($steps) / $disp : null, 4), 'maxDeviation' => self::rounded($maxDev, 2), 'stepCv' => self::rounded(self::cv($steps)), 'linear' => $linear, 'polynomial' => $poly];
    }

    public static function analyze(mixed $trace, array $ctx = []): array
    {
        if ($trace === null) return ['score' => null, 'verdict' => 'missing', 'flags' => [], 'signals' => [], 'digest' => null];
        $invalid = self::validate($trace);
        if ($invalid !== null) return self::finish(['bh_malformed'], ['malformed' => $invalid], null, $ctx);
        $t = $trace;
        $a = $t['a'];
        $fine = $t['res'] <= 2;
        $flags = [];
        $signals = ['clockRes' => $t['res'], 'moves' => count($t['m']), 'untrustedPageEvents' => $t['n'][0] + $t['n'][1], 'unexplainedScrolls' => $t['n'][7]];
        if (!empty($ctx['replayed'])) $flags[] = 'bh_replayed';
        $kb = self::typing($t['k']);
        if ($kb !== null) {
            $signals['typing'] = ['keys' => $kb['keys']];
            foreach (['intervalMedian', 'intervalCv', 'intervalRegular', 'dwellCv', 'dwellRegular', 'dwellMedian'] as $key) $signals['typing'][$key] = self::rounded($kb[$key], str_ends_with($key, 'Median') ? 1 : 3);
            if ($fine && $kb['intervalRegular'] !== null && $kb['intervalRegular'] >= .75 && ($kb['dwellRegular'] === null || $kb['dwellRegular'] >= .6)) $flags[] = 'bh_uniform_typing';
            if ($fine && $kb['intervalMedian'] !== null && $kb['intervalMedian'] < 25) $flags[] = 'bh_superhuman_typing';
        }
        if ($a === null) {
            $signals['activation'] = 'none';
            return self::finish($flags, $signals, $t, $ctx, true);
        }
        $signals['activation'] = ['pointer', 'key', 'click'][$a['k']];
        if ($a['tr'] === 0) $flags[] = 'bh_untrusted_activation';
        if ($a['hid'] === 1) $flags[] = 'bh_hidden_activation';
        $dwell = $a['u'] >= 0 ? $a['u'] - $a['t'] : null;
        $signals['pressMs'] = self::rounded($dwell, 1);
        $signals['sinceVisibleMs'] = $t['vis'] >= 0 ? self::rounded($a['t'] - $t['vis'], 0) : null;
        $signals['idleMs'] = $t['last'] >= 0 ? self::rounded($a['t'] - $t['last'], 0) : null;
        if ($a['k'] === 0) {
            $signals['pointerType'] = ['mouse', 'pen', 'touch'][$a['pt']];
            if ($t['vis'] < 0) $flags[] = 'bh_activation_unseen';
            elseif ($fine && $a['t'] - $t['vis'] < 120) $flags[] = 'bh_too_fast';
            if ($a['sox'] == 0 && $a['soy'] == 0 && !$t['env'][4] && $t['env'][5] >= 40) $flags[] = 'bh_screen_coords_leak';
            if ($a['w'] >= 16 && $a['h'] >= 16 && abs($a['cx']) < .75 && abs($a['cy']) < .75) $flags[] = 'bh_center_hit';
            if ($t['us'] >= 0 && $a['t'] >= $t['us'] && $a['t'] - $t['us'] < 2000) $flags[] = 'bh_unexplained_scroll';
            if ($fine && $dwell !== null && $dwell < 8) $flags[] = 'bh_instant_press';
            if ($a['pt'] === 0) {
                $before = array_values(array_filter($t['m'], static fn ($p) => $p[4] === 0 && $p[0] <= $a['t']));
                $i = count($before);
                while ($i > 0 && hypot($before[$i - 1][1] - $a['x'], $before[$i - 1][2] - $a['y']) <= 30) $i--;
                if ($i === 0) {
                    $warped = count($before) <= 2;
                } else {
                    $prev = $before[$i - 1];
                    $first = $before[$i] ?? null;
                    $jump = $first ? hypot($first[1] - $prev[1], $first[2] - $prev[2]) : hypot($a['x'] - $prev[1], $a['y'] - $prev[2]);
                    $warped = $jump > 150 && ($first ? $first[3] <= 1 : true);
                }
                $signals['approachSamples'] = count($before);
                if ($warped) {
                    $flags[] = 'bh_warp_click';
                    if ($signals['idleMs'] !== null && $signals['idleMs'] >= 3000) $flags[] = 'bh_idle_then_act';
                }
                $path = self::path($t['m'], $a);
                if ($path !== null) {
                    $signals['path'] = array_diff_key($path, ['linear' => true, 'polynomial' => true]);
                    if ($path['linear']) $flags[] = 'bh_linear_path';
                    elseif ($path['polynomial']) $flags[] = 'bh_polynomial_path';
                }
            }
            if ($a['pt'] === 2 && $a['cw'] <= 2 && $a['ch'] <= 2 && ($a['pr'] == 1 || $a['pr'] == .5)) $flags[] = 'bh_touch_geometry';
        } elseif ($a['k'] === 1 && $fine && $dwell !== null && $dwell < 8) {
            $flags[] = 'bh_instant_key';
        }
        return self::finish($flags, $signals, $t, $ctx);
    }

    private static function finish(array $flags, array $signals, ?array $trace, array $ctx, bool $noActivation = false): array
    {
        $keep = 1;
        $strong = false;
        foreach ($flags as $f) {
            $keep *= 1 - self::WEIGHTS[$f];
            $strong = $strong || in_array($f, self::STRONG, true);
        }
        $score = 1 - $keep;
        if (!$strong && empty($ctx['strict'])) $score = min($score, .6);
        $score = self::rounded($score);
        $verdict = $score >= ($ctx['threshold'] ?? .75) ? 'automated' : ($score >= .4 ? 'suspicious' : ($noActivation ? 'no_interaction' : 'ok'));
        $digest = null;
        if ($trace !== null && count($trace['m']) + count($trace['k']) >= 8) {
            $parts = [];
            $q = static fn ($x, $s) => (int)floor($x / $s + .5);
            foreach ($trace['m'] as $p) array_push($parts, $q($p[0], 4), $q($p[1], 2), $q($p[2], 2));
            $parts[] = '|';
            foreach ($trace['k'] as $k) $parts[] = $q($k[1], 4);
            if ($trace['a'] !== null) array_push($parts, '|', $trace['a']['k'], $q($trace['a']['cx'] ?? 0, 2), $q($trace['a']['cy'] ?? 0, 2));
            $digest = hash('sha256', implode(',', $parts));
        }
        return ['score' => $score, 'verdict' => $verdict, 'flags' => $flags, 'signals' => $signals, 'digest' => $digest];
    }
}
