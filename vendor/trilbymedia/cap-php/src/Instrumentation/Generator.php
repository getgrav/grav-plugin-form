<?php

declare(strict_types=1);

namespace TrilbyMedia\Cap\Instrumentation;

use TrilbyMedia\Cap\InstrumentationOptions;

/** Per-challenge arithmetic and DOM program, using upstream's browser template. @internal */
final class Generator
{
    public static function generate(InstrumentationOptions $options, int $now, int $expires, bool $behavior = false, bool $behaviorEnforced = false): array
    {
        $id = bin2hex(random_bytes(16));
        $vars = array_map(static fn () => 'v' . bin2hex(random_bytes(6)), range(0, 3));
        $initial = array_map(static fn () => random_int(10, 250), range(0, 3));
        $values = $initial;
        $dom = 'd' . bin2hex(random_bytes(6));
        $fn = 'f' . bin2hex(random_bytes(6));
        $correct = random_int(1000, 9000);
        $bad = $correct ^ 1023;
        $values[0] ^= $correct;
        $equations = "function {$fn}(a,b,c){function F(d){this.v=function(){return this.k^d;}}var p={k:c};var i=new F(a);i.k=b;F.prototype=p;return i.v()|(new F(b)).v();}"
            . "function {$dom}(x,y,z){var d=document.createElement('div');d.style.display='none';document.body.appendChild(d);function A(p,v){for(var i=0;i<8;i++){var c=document.createElement('div');p.appendChild(c);c.textContent=v;if((v&1)==0)p=c;v=v>>1;}return p;}function B(n,r,s){if(!n||n==r)return s%256;while(n.children.length>0)n.removeChild(n.lastElementChild);return B(n.parentNode,r,s+parseInt(n.textContent));}var s=B(A(A(A(d,x),y),z),d,0);d.parentNode.removeChild(d);return s;}"
            . "{$vars[0]}^=(navigator.userAgent?{$correct}:{$bad});";
        for ($i = 0; $i < 20; $i++) {
            [$dest, $s1, $s2, $s3] = array_map(static fn () => random_int(0, 3), range(0, 3));
            [$d, $a, $b, $c] = [$vars[$dest], $vars[$s1], $vars[$s2], $vars[$s3]];
            $op = random_int(0, 5);
            if ($op === 0) {
                $expr = "~({$d}&{$a})";
                $v = ~($values[$dest] & $values[$s1]);
            } elseif ($op === 1) {
                $expr = "{$d}^{$a}";
                $v = $values[$dest] ^ $values[$s1];
            } elseif ($op === 2) {
                $expr = "{$d}|{$a}";
                $v = $values[$dest] | $values[$s1];
            } elseif ($op === 3) {
                $expr = "{$d}&{$a}";
                $v = $values[$dest] & $values[$s1];
            } elseif ($op === 4) {
                $expr = "{$fn}({$a},{$b},{$d})";
                $v = ($values[$s2] ^ $values[$s1]) | ($values[$dest] ^ $values[$s2]);
            } else {
                $expr = "{$dom}({$a},{$b},{$c})";
                $v = self::domSum($values[$s1], $values[$s2], $values[$s3]);
            }
            $equations .= "{$d}={$expr};";
            $values[$dest] = self::int32($v);
        }
        // As upstream, avoid trivial programs with fewer than two nonzero outputs.
        if (count(array_filter($values, static fn ($v) => $v !== 0)) < 2) {
            return self::generate($options, $now, $expires, $behavior, $behaviorEnforced);
        }
        $salts = array_map(static fn () => random_int(100_000, 999_999), range(0, 3));
        $seeds = [random_int(1, 0x7FFFFFFF), random_int(1, 0x7FFFFFFF)];
        $keys = [];
        foreach ($seeds as $seed) {
            $key = random_int(1, 0x7FFFFFFF);
            array_push($keys, $key, self::int32($seed ^ Encoding::hash(implode(',', $values), $key)));
        }
        $interactions = $options->detectAutomatedInteractions || $options->blockAutomatedInteractions;
        $cfg = json_decode(file_get_contents(__DIR__ . '/collector-config.json'), true, 512, JSON_THROW_ON_ERROR);
        $cfg['ia'] = $interactions ? 1 : 0;
        $cfg['bh'] = $behavior ? 1 : 0;
        $replacements = [
            '__CAP_ID__' => $id,
            '__CAP_EQUATIONS__' => $equations,
            '__CAP_CONFIG__' => Encoding::json($cfg),
            '__CAP_BLOCK__' => $options->blockAutomatedBrowsers ? 'true' : 'false',
            '__CAP_OBSERVE__' => ($behavior || $interactions) ? 'true' : 'false',
        ];
        for ($i = 0; $i < 4; $i++) {
            $replacements['__CAP_VAR_' . $i . '__'] = $vars[$i];
            $replacements['__CAP_INIT_' . $i . '__'] = (string)$initial[$i];
            $replacements['__CAP_SALT_' . $i . '__'] = (string)$salts[$i];
            $replacements['__CAP_KEY_' . $i . '__'] = (string)$keys[$i];
        }
        $script = strtr(file_get_contents(__DIR__ . '/client.js'), $replacements);
        return [
            'id' => $id, 'iat' => $now, 'expires' => $expires, 'vars' => $vars,
            'blockAutomatedBrowsers' => $options->blockAutomatedBrowsers,
            'detectAutomatedInteractions' => $interactions,
            'blockAutomatedInteractions' => $options->blockAutomatedInteractions,
            'behavior' => $behavior, 'behaviorEnforced' => $behaviorEnforced,
            'binding' => ['pv' => $values, 'sl' => $salts, 'hs' => $seeds, 'j' => [0, 1]],
            'instrumentation' => base64_encode(Encoding::deflate($script)),
        ];
    }

    private static function int32(int $v): int
    {
        $v &= 0xFFFFFFFF;
        return $v > 0x7FFFFFFF ? $v - 0x100000000 : $v;
    }

    private static function domSum(int $x, int $y, int $z): int
    {
        $sum = 0;
        foreach ([$x, $y, $z] as $v) {
            for ($i = 0; $i < 8; $i++) {
                if (($v & 1) === 0) {
                    $sum += $v;
                }
                $v >>= 1;
            }
        }
        return $sum % 256;
    }
}
