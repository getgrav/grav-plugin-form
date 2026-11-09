<?php

declare(strict_types=1);

namespace TrilbyMedia\Cap\Instrumentation;

/** Applies captured behavior policy to verified, bound data. @internal */
final class Policy
{
    public static function evaluate(array $verified, ?array $policy, array $headers, ?\Closure $replayCheck, int $ttlMs): array
    {
        $bound = $verified['agent'] ?? null;
        $trace = $verified['trace'] ?? null;
        if ($policy !== null && $policy['blockAgents'] !== false && ($bound['host'] ?? null) === false) {
            return ['valid' => false, 'reason' => $bound['isolated'] ? 'instr_isolated' : ($bound['legacy'] ? 'instr_widget_outdated' : 'instr_host_unreachable'), 'instr_error' => true];
        }
        $pre = $policy !== null ? Behavior::analyze($trace, $policy) : null;
        if ($pre !== null && $pre['digest'] !== null && $replayCheck !== null) {
            try {
                if ($replayCheck($pre['digest'], max(60000, $ttlMs)) === true) {
                    $pre = Behavior::analyze($trace, $policy + ['replayed' => true]);
                }
            } catch (\Throwable $e) {
                // Match upstream's optional advisory replay callback.
            }
        }
        $evidence = $policy !== null && $trace !== null && Behavior::validate($trace) === null ? Agents::fromTrace($trace) : [];
        foreach ($evidence as &$item) {
            if ($item['agent'] === 'gemini-in-chrome' && ($bound['browser'] ?? null) === 'brave') $item['agent'] = 'brave-leo';
        }
        unset($item);
        $seen = array_column($evidence, 'evidence');
        foreach (Agents::fromCodes($bound['all'] ?? []) as $item) {
            if (!in_array($item['evidence'], $seen, true)) $evidence[] = $item;
        }
        $evidence = array_merge($evidence, Agents::fromHeaders($headers));
        $agent = Agents::summarize($evidence);
        $flags = $verified['riskFlags'] ?? [];
        if ($policy === null) {
            if ($agent !== null && $agent['detected']) $flags[] = 'agent:' . $agent['name'];
            return ['valid' => true, 'riskFlags' => $flags] + ($agent !== null ? ['agent' => $agent] : []);
        }
        $verdict = $pre['verdict'];
        if ($agent !== null && $agent['detected'] && $verdict !== 'automated') $verdict = 'agent';
        $behavior = ['score' => $pre['score'], 'verdict' => $verdict, 'flags' => $pre['flags'], 'signals' => $pre['signals'] ?: new \stdClass()] + ($agent !== null ? ['agent' => $agent] : []);
        $flags = array_merge($flags, $pre['verdict'] === 'missing' ? ['bh_missing'] : $pre['flags']);
        if ($agent !== null && $agent['detected']) $flags[] = 'agent:' . $agent['name'];
        $reason = null;
        if ($agent !== null && $agent['detected'] && ($policy['blockAgents'] === 'all' || ($policy['blockAgents'] === 'identified' && $agent['confidence'] === 'high'))) {
            $reason = 'behavior_agent';
        } elseif ($pre['verdict'] === 'missing') {
            if (!(($bound['legacy'] ?? false) || ($bound['isolated'] ?? false)) || $policy['block']) $reason = 'behavior_missing';
        } elseif ($policy['block']) {
            if ($pre['verdict'] === 'automated') $reason = 'behavior_automated';
            elseif ($policy['requireInteraction'] && $pre['verdict'] === 'no_interaction') $reason = 'behavior_no_interaction';
        }
        return $reason !== null ? ['valid' => false, 'reason' => $reason, 'behavior' => $behavior] : ['valid' => true, 'riskFlags' => $flags, 'behavior' => $behavior];
    }
}
