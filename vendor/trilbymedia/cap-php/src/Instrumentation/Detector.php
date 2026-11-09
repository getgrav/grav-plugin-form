<?php

declare(strict_types=1);

namespace TrilbyMedia\Cap\Instrumentation;

/** Browser probe rules from capjs-core 0.1.11 detect.js. @internal */
final class Detector
{
    private static function num(mixed $v): bool
    {
        return (is_int($v) || is_float($v)) && is_finite((float)$v);
    }

    public static function detect(array $s, ?\stdClass $raw = null): array
    {
        $blocked = [];
        $risk = [];
        $add = static function (string $id, bool $passed, bool $gating = true) use (&$blocked, &$risk): void {
            if (!$passed) {
                if ($gating) {
                    $blocked[] = $id;
                } else {
                    $risk[] = $id;
                }
            }
        };
        $types = ['ua' => 'string', 'productSub' => 'string', 'oscpu' => 'string', 'uaDataPresent' => 'boolean', 'uaData' => '?object', 'plugins' => 'object', 'engine' => 'object', 'screen' => 'object', 'outerWH' => 'array', 'isExtended' => '?boolean', 'fontWidths' => 'array', 'maxTouchPoints' => 'number', 'mainNav' => 'object', 'tamper' => '?object'];
        $complete = true;
        foreach ($types as $field => $type) {
            $v = $s[$field] ?? null;
            if ($v === null && str_starts_with($type, '?') && array_key_exists($field, $s)) {
                continue;
            }
            $type = ltrim($type, '?');
            $original = $raw !== null ? ($raw->$field ?? null) : $v;
            $complete = $complete && match ($type) {
                'number' => self::num($v),
                'object' => $raw !== null ? $original instanceof \stdClass : is_array($v) && ($v === [] || !array_is_list($v)),
                'array' => is_array($original) && array_is_list($original),
                default => gettype($v) === $type,
            };
        }
        $fw = is_array($s['fontWidths'] ?? null) ? $s['fontWidths'] : [];
        $add('probe_incomplete', $complete && count($fw) === 17);
        foreach (['uaData', 'engine', 'screen', 'stealth', 'hev', 'mainNav', 'workerNav', 'tamper', 'host', 'cdp', 'gpu', 'glsh', 'innerWH', 'outerWH', 'activeText', 'langs', 'cv', 'voices'] as $field) {
            if (!is_array($s[$field] ?? null)) $s[$field] = [];
        }
        $ua = is_string($s['ua'] ?? null) ? $s['ua'] : '';
        $gecko = ($s['engine']['hasMozInnerScreenX'] ?? null) === true || (($s['productSub'] ?? null) === '20100101' && ($s['uaDataPresent'] ?? null) === false) || (is_string($s['oscpu'] ?? null) && $s['oscpu'] !== '__undefined');
        $chromium = ($s['uaDataPresent'] ?? null) === true || ($s['engine']['hasChrome'] ?? null) === true;
        $mobile = ($s['uaData']['mobile'] ?? null) === true || preg_match('/Android|iPhone|iPad|iPod|IEMobile|BlackBerry|Mobile Safari|; Mobile|Silk|Kindle|Tizen|Opera Mini/i', $ua) === 1;
        $widths = array_values(array_filter(array_slice($fw, 0, 64), [self::class, 'num']));
        $whole = array_values(array_filter($widths, static fn ($n) => (float)(int)$n === (float)$n));
        $blank = count(array_filter($widths, static fn ($n) => $n <= 0));
        $geo = count($widths) >= 6;
        $dpr = $s['screen']['dpr'] ?? null;
        $hinted = self::num($dpr) && !$mobile && ((preg_match('/\bCrOS\b/', $ua) && round($dpr) == $dpr) || (preg_match('/\bLinux\b/', $ua) && !str_contains($ua, 'Android') && $dpr <= 1));
        $add('geometry_quantized', !($geo && count($whole) >= 5 && count(array_unique($whole)) >= 3 && !$hinted));
        $add('geometry_unmeasured', !$geo || $blank < count($widths));
        $add('webdriver_true', ($s['webdriver'] ?? null) !== true);
        $add('webdriver_stripped', !($chromium && !array_key_exists('webdriver', $s)));
        $add('gecko_contradiction', !($gecko && (self::num($s['deviceMemory'] ?? null) || ($s['uaDataPresent'] ?? null) === true)));
        $sc = $s['screen'] ?? [];
        $ow = $s['outerWH'] ?? [];
        $iw = $s['innerWH'] ?? [];
        $add('window_exceeds_screen', !(self::num($ow[1] ?? null) && self::num($sc['height'] ?? null) && $ow[1] > $sc['height'] + 16 && ($s['isExtended'] ?? null) === false));
        $add('viewport_override', !(!$mobile && self::num($iw[0] ?? null) && self::num($iw[1] ?? null) && ($iw[0] == ($sc['width'] ?? null)) && ($iw[1] == ($sc['height'] ?? null)) && self::num($ow[1] ?? null) && $ow[1] > $iw[1]));
        $brands = is_array($s['uaData']['brands'] ?? null) ? implode(' ', array_filter($s['uaData']['brands'], 'is_string')) : '';
        $add('headless_token', !preg_match('/headlesschrome/i', $brands . ' ' . $ua));
        $hev = $s['hev'] ?? null;
        $add('ua_hints_blank', !($chromium && !$mobile && ($s['uaDataPresent'] ?? null) === true && (($s['uaData']['brands'] ?? null) === [] || (is_array($hev) && ($hev['fv'] ?? null) === '' && ($hev['fl'] ?? null) === ''))));
        $add('stealth_patches', ($s['stealth']['iframe'] ?? null) !== true && ($s['stealth']['runtime'] ?? null) !== true);
        $add('juggler_package', !($gecko && ($s['juggler'] ?? null) === true));
        $add('chrome_object_missing', !(($s['uaDataPresent'] ?? null) === true && !$mobile && ($s['maxTouchPoints'] ?? null) === 0 && ($s['engine']['hasChrome'] ?? null) === false && !preg_match('/QtWebEngine/i', $ua)));
        $mn = $s['mainNav'] ?? null;
        $wn = $s['workerNav'] ?? null;
        $mismatched = false;
        if (is_array($mn) && is_array($wn)) {
            $fields = ['ua', 'platform', 'tz', 'brands'];
            if (($mn['brave'] ?? null) !== true) {
                array_push($fields, 'languages', 'hc', 'gl');
            }
            $ios = ($mn['platform'] ?? null) === 'MacIntel' && preg_match('/^(iPad|iPhone|iPod)/', $wn['platform'] ?? '') && ($s['maxTouchPoints'] ?? 0) > 1;
            foreach ($fields as $f) {
                if (isset($mn[$f], $wn[$f]) && !($f === 'platform' && $ios) && $mn[$f] !== $wn[$f]) {
                    $mismatched = true;
                }
            }
        }
        $add('worker_mismatch', !$mismatched);
        $tampered = array_filter($s['tamper'] ?? [], static fn ($v) => $v === false);
        $lies = is_array($s['lies'] ?? null) ? array_filter($s['lies'], 'is_string') : [];
        $add('native_tamper', !$tampered && !$lies, false);
        $eval = $s['evalLen'] ?? null;
        $claimsGecko = preg_match('/Firefox\//', $ua) && !preg_match('/Seamonkey/i', $ua);
        $claimsBlink = preg_match('/Chrome\/|CriOS\//', $ua);
        $claimsWebkit = str_contains($ua, 'Safari/') && !$claimsBlink;
        $add('engine_mismatch', !(!$mobile && self::num($eval) && (($eval === 33 && ($claimsGecko || $claimsWebkit)) || ($eval === 37 && $claimsBlink))), false);
        preg_match('/Chrome\/(\d+)/', $ua, $major);
        $at = $s['activeText'] ?? [];
        $add('os_mismatch', !(str_contains($ua, 'Windows NT') && (int)($major[1] ?? 0) >= 138 && ($at[0] ?? null) === 'rgb(255, 0, 0)' && ($at[1] ?? null) === false), false);
        $lg = $s['langs'] ?? [];
        $add('languages_inconsistent', !(($lg[0] ?? null) === 0 || (self::num($lg[0] ?? null) && $lg[0] > 0 && ($lg[1] ?? null) === false)), false);
        $host = $s['host'] ?? null;
        $hn = $host['nav'] ?? null;
        $frameDiff = false;
        if (is_array($hn) && is_array($mn)) {
            $fields = ['platform', 'brands'];
            if (($mn['brave'] ?? null) !== true) {
                array_push($fields, 'languages', 'hc');
            }
            foreach ($fields as $f) {
                if (isset($mn[$f], $hn[$f]) && $mn[$f] !== $hn[$f]) {
                    $frameDiff = true;
                }
            }
            $frameDiff = $frameDiff || (($hn['webdriver'] ?? null) === true && ($s['webdriver'] ?? null) !== true);
        }
        $add('frame_mismatch', !$frameDiff, false);
        $add('document_intercepted', ($host['doc'] ?? null) !== true, false);
        $add('cdp_console_trap', ($s['cdp']['trap'] ?? null) !== true, false);
        $add('attach_shadow_patched', ($s['cdp']['shadow'] ?? null) !== false, false);
        $gl = is_string($mn['gl'] ?? null) ? $mn['gl'] : '';
        $add('software_renderer', !(!$mobile && (($s['gpu']['f'] ?? null) === true || preg_match('/SwiftShader|llvmpipe|softpipe|Mesa OffScreen|Microsoft Basic Render/i', $gl))), false);
        $cv = $s['cv'] ?? [];
        $add('canvas_noise', !(($cv[0] ?? null) === 0 && self::num($cv[1] ?? null) && $cv[1] > 64), false);
        $arch = strtolower(is_string($hev['ar'] ?? null) ? $hev['ar'] : '');
        $nan = ($s['na'] ?? null) === 255 ? 'x86' : (($s['na'] ?? null) === 127 ? 'arm' : null);
        $desktop = $arch === 'x86' && $nan === 'arm' && preg_match('/^Linux (armv|aarch64)/', $mn['platform'] ?? '');
        $add('arch_mismatch', !($nan !== null && in_array($arch, ['x86', 'arm'], true) && $arch !== $nan && !$desktop));
        $ig = is_array($s['ig'] ?? null) ? array_filter($s['ig'], 'is_string') : [];
        $add('injected_globals', !$ig, false);
        $sd = is_array($s['sd'] ?? null) ? array_filter($s['sd'], 'is_string') : [];
        $forged = array_intersect($sd, ['isTrusted', 'objectToInspect']);
        $add('devtools_globals', !$forged);
        $add('stealth_descriptors', !array_diff($sd, $forged), false);
        $no = is_array($s['no'] ?? null) ? array_filter($s['no'], 'is_string') : [];
        $add('navigator_order', !(($chromium || $eval === 33) && $no), false);
        $glsh = $s['glsh'] ?? null;
        $renderer = is_string($glsh['r'] ?? null) ? $glsh['r'] : $gl;
        $translated = $glsh['t'] ?? null;
        $metal = preg_match('/Metal|^Apple GPU$/i', $renderer) === 1;
        $add('shader_mismatch', !($renderer !== '' && in_array($translated, ['hlsl', 'metal', 'glsl'], true) && $metal !== ($translated === 'metal')), false);
        $gpuFamily = !empty($s['gpu']) && empty($s['gpu']['n']) ? self::family($s['gpu']['v'] ?? '') : null;
        $glFamily = self::family($renderer);
        $add('webgpu_mismatch', !($gpuFamily && $glFamily && $gpuFamily !== $glFamily && !$mobile), false);
        $vc = $s['voices'] ?? [];
        $add('voices_missing', !(preg_match('/(^|\s)Google Chrome\//', $brands) && !$mobile && self::num($vc[0] ?? null) && $vc[0] > 0 && ($vc[1] ?? null) === 0), false);
        $hasFont = static fn ($i) => self::num($fw[$i] ?? null) && self::num($s['fm'] ?? null) && abs($fw[$i] - $s['fm']) > 0.5;
        $knownFonts = self::num($s['fm'] ?? null) && count($fw) >= 13;
        $add('os_fonts_mismatch', !($knownFonts && ((str_contains($ua, 'Windows NT') && !$mobile && !$hasFont(6)) || (str_contains($ua, 'Macintosh') && !$hasFont(8)))), false);
        $attrs = is_array($host['ha'] ?? null) ? $host['ha'] : [];
        $add('html_automation_attr', !array_filter($attrs, static fn ($k) => is_string($k) && preg_match('/playwright|puppeteer|selenium|webdriver|cdp|automation|crawl|scrap/i', $k)), false);
        return ['pass' => !$blocked, 'blockedBy' => $blocked, 'riskFlags' => $risk];
    }

    private static function family(mixed $s): ?string
    {
        $s = is_scalar($s) ? (string)$s : '';
        foreach (['nvidia' => '/nvidia|geforce|quadro|rtx/i', 'amd' => '/\bamd\b|radeon|\bati\b/i', 'intel' => '/intel/i', 'apple' => '/apple/i', 'qualcomm' => '/qualcomm|adreno/i', 'arm' => '/\bmali\b|\barm\b/i', 'google' => '/swiftshader|google/i'] as $family => $pattern) {
            if (preg_match($pattern, $s)) {
                return $family;
            }
        }
        return null;
    }
}
