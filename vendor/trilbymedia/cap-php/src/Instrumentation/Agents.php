<?php

declare(strict_types=1);

namespace TrilbyMedia\Cap\Instrumentation;

/** Local agent evidence from capjs-core 0.1.11. No network requests or signature verification. @internal */
final class Agents
{
    private const RANK = ['low' => 1, 'medium' => 2, 'high' => 3];

    public static function fromCodes(array $codes): array
    {
        $artifacts = json_decode(file_get_contents(__DIR__ . '/agent-artifacts.json'), true, 512, JSON_THROW_ON_ERROR);
        $found = [];
        foreach ($codes as $code) {
            if (isset($artifacts[$code])) {
                $a = $artifacts[$code];
                $found[] = ['agent' => $a['agent'], 'confidence' => $a['confidence'], 'evidence' => 'artifact:' . $code];
            }
        }
        return $found;
    }

    public static function fromHeaders(array $headers): array
    {
        $headers = array_change_key_case($headers, CASE_LOWER);
        $header = static fn ($key) => is_array($headers[$key] ?? null) ? implode(', ', $headers[$key]) : (string)($headers[$key] ?? '');
        $ua = $header('user-agent');
        $found = [];
        $tokens = [
            '/\bClaude-Agent\/\d/' => ['claude-agent', 'high'], '/\bGoogle-Agent\b/' => ['google-agent', 'high'], '/\bManus-User\/\d/' => ['manus', 'high'], '/\bChatGPT-User\/\d/' => ['chatgpt-user', 'high'],
            '/\bGrokAgent\b/' => ['grok-bot', 'medium'], '/\bAgent-NovaAct\/\d/' => ['nova-act', 'high'], '/\bTwinAgent\/\d/' => ['twin', 'high'], '/\bCloudflareBrowserRenderingCrawler\/\d/' => ['cloudflare-browser-run', 'high'],
            '/\bClaude-User\b/' => ['claude-user', 'high'], '/\bclaude-code\/\d/' => ['claude-code', 'high'], '/\bPerplexity-User\/\d/' => ['perplexity-user', 'high'], '/\bMistralAI-User\/\d/' => ['mistral-user', 'high'],
            '/\bmeta-externalfetcher\//i' => ['meta-externalfetcher', 'high'], '/\bHarkBot\/\d/' => ['harkbot', 'high'], '/\) Claude\/\d+\.\d+[\d.]* Chrome\/\d/' => ['claude-desktop', 'medium'],
        ];
        foreach ($tokens as $pattern => [$agent, $confidence]) {
            if (preg_match($pattern, $ua)) $found[] = ['agent' => $agent, 'confidence' => $confidence, 'evidence' => 'ua:' . $agent];
        }
        $known = ['chatgpt.com' => 'chatgpt-agent', 'agent.bot.goog' => 'google-agent', 'api.manus.im' => 'manus', 'cursorusercontent.com' => 'grok-bot', 'www.browserbase.com' => 'browserbase', 'www.kernel.sh' => 'kernel', 'search.bot.kernel.sh' => 'kernel', 'hark.com' => 'harkbot', 'www.hyperbrowser.ai' => 'hyperbrowser', 'api.hyperbrowser.ai' => 'hyperbrowser', 'api.anchorbrowser.io' => 'anchor-browser', 'web-bot-auth.cloudflare-browser-rendering-085.workers.dev' => 'cloudflare-browser-run', 'api.agi.tech' => 'agi-agent'];
        preg_match_all('/(?:([a-z*][a-z0-9_.*-]*)=)?"(https:\/\/[^"]+)"/i', $header('signature-agent'), $matches, PREG_SET_ORDER);
        foreach ($matches as $m) {
            $parts = parse_url($m[2]);
            if (empty($parts['host'])) continue;
            $host = strtolower($parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '');
            $agent = $known[$host] ?? (preg_match('/^[a-z0-9-]+\.keydirectory\.signer\.[a-z0-9-]+\.on\.aws$/D', $host) ? 'aws-agentcore' : 'signed:' . $host);
            $found[] = ['agent' => $agent, 'confidence' => 'medium', 'evidence' => 'signature_agent:' . $host, 'signatureAgent' => $m[2]];
        }
        $platform = str_replace('"', '', $header('sec-ch-ua-platform'));
        $uaPlatform = str_contains($ua, 'Windows') ? 'Windows' : (preg_match('/Mac OS X|Macintosh/', $ua) ? 'macOS' : (str_contains($ua, 'Android') ? 'Android' : (preg_match('/Linux|X11/', $ua) ? 'Linux' : null)));
        if ($platform && $uaPlatform && $platform !== $uaPlatform && !($uaPlatform === 'Linux' && $platform === 'Chrome OS')) $found[] = ['agent' => null, 'confidence' => 'low', 'evidence' => "ua_platform_mismatch:{$uaPlatform}/{$platform}"];
        return $found;
    }

    public static function fromTrace(array $t): array
    {
        $found = [];
        $add = static function (?string $agent, string $confidence, string $evidence) use (&$found): void {
            $found[] = ['agent' => $agent, 'confidence' => $confidence, 'evidence' => $evidence];
        };
        $a = $t['a'];
        if ($a && $a['k'] === 0 && $a['pt'] === 0 && $a['u'] >= 0) {
            $before = array_values(array_filter($t['m'], static fn ($p) => $p[4] === 0 && $p[0] <= $a['t']));
            $last = $before ? $before[count($before) - 1] : null;
            $recent = array_filter($before, static fn ($p) => $p[0] > $a['t'] - 1000);
            $warp = $last && hypot($last[1] - $a['x'], $last[2] - $a['y']) < 1 && count($recent) <= 2;
            $gap = $last ? $a['t'] - $last[0] : null;
            $dwell = $a['u'] - $a['t'];
            $center = abs($a['cx']) < .01 && abs($a['cy']) < .01;
            $integer = floor($a['x']) == $a['x'] && floor($a['y']) == $a['y'];
            if ($warp && $t['res'] <= 2) {
                if ($gap < 6 && $dwell < 4) $add('cdp-automation', 'medium', 'click:cdp_default');
                elseif ($center && $gap >= 35 && $gap <= 90 && $dwell >= 65 && $dwell <= 130) $add('browser-use', 'medium', 'click:browser_use');
                elseif ($integer && $dwell >= 9 && $dwell <= 30 && $gap >= 80 && $gap <= 900) $add('claude-in-chrome', 'medium', 'click:claude_in_chrome');
                elseif ($integer && abs($a['cx']) <= 1 && abs($a['cy']) <= 1 && $gap >= 4 && $gap <= 15 && $dwell >= 4 && $dwell <= 15) $add('gemini-in-chrome', 'medium', 'click:chrome_actor');
                elseif ($center && $a['w'] >= 16 && $a['h'] >= 16) $add(null, 'medium', 'click:exact_center_warp');
            }
        }
        if ($a && $a['k'] === 0 && $a['pt'] === 0 && $a['pr'] == 0 && $a['u'] >= 0 && $a['u'] - $a['t'] < 1 && abs($a['cx']) <= .5 && abs($a['cy']) <= .5 && !array_filter($t['m'], static fn ($p) => $p[4] === 0 && $p[0] <= $a['t'] && $p[0] > $a['t'] - 1000)) $add(null, 'low', 'click:ax_simulated');
        if ($t['res'] <= 2 && count($t['k']) >= 7) {
            $iv = [];
            $held = array_values(array_filter(array_column($t['k'], 1), static fn ($x) => $x >= 0));
            for ($i = 1; $i < count($t['k']); $i++) {
                $d = $t['k'][$i][0] - $t['k'][$i - 1][0];
                if ($d >= 0 && $d < 2000) $iv[] = $d;
            }
            sort($iv, SORT_NUMERIC);
            sort($held, SORT_NUMERIC);
            $mid = static fn ($x) => $x[intdiv(count($x), 2)];
            $spread = static fn ($x) => $x[(int)floor(count($x) * .75)] - $x[(int)floor(count($x) * .25)];
            if (count($iv) >= 6 && count($held) >= 6 && $mid($held) >= 20 && $mid($held) <= 32 && $mid($iv) >= 42 && $mid($iv) <= 62 && $spread($iv) <= 8 && $spread($held) <= 6) $add('gemini-in-chrome', 'medium', 'typing:chrome_actor');
        }
        if (($t['n'][10] ?? 0) >= 2) $add(null, 'low', 'typing:phantom_shift');
        if ($t['res'] <= 2 && count($t['k']) >= 7 && count($iv) >= 6 && $mid($iv) < 25) $add(null, 'medium', 'typing:superhuman');
        if ($t['n'][8] > 0) $add(null, 'low', 'typing:keyless_insert');
        if ($t['n'][9] > 0) $add(null, 'low', 'input:untrusted');
        return $found;
    }

    public static function summarize(array $found): ?array
    {
        if (!$found) return null;
        $best = 0;
        $named = null;
        foreach ($found as $f) {
            $best = max($best, self::RANK[$f['confidence']]);
            if ($f['agent'] && ($named === null || self::RANK[$f['confidence']] > self::RANK[$named['confidence']])) $named = $f;
        }
        return ['detected' => $named !== null && self::RANK[$named['confidence']] >= 2, 'name' => $named['agent'] ?? null, 'confidence' => array_search($best, self::RANK, true), 'verified' => false, 'evidence' => array_column($found, 'evidence')];
    }
}
