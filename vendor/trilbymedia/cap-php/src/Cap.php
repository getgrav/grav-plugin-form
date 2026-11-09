<?php

declare(strict_types=1);

namespace TrilbyMedia\Cap;

use TrilbyMedia\Cap\Instrumentation\Generator;
use TrilbyMedia\Cap\Instrumentation\Verifier;
use TrilbyMedia\Cap\Instrumentation\Policy;
use TrilbyMedia\Cap\Storage\AtomicChallengeStorageInterface;
use TrilbyMedia\Cap\Storage\AtomicTokenStorageInterface;

/**
 * Cap proof-of-work captcha server.
 *
 * Wire-compatible with the official @cap.js/widget. The client widget
 * POSTs to two endpoints you expose:
 *
 *   POST /challenge → Cap::createChallenge() response
 *   POST /redeem    → Cap::redeemChallenge($body['token'], $body['solutions'])
 *
 * When the form is submitted, validate the token the widget put in the
 * form with Cap::validateToken().
 */
final class Cap
{
    private const CLEANUP_INTERVAL_MS = 300_000; // 5 min

    private int $lastCleanupMs = 0;

    public function __construct(private readonly Config $config) {}

    /**
     * Generate a new challenge.
     *
     * @return array<string, mixed>
     */
    public function createChallenge(?ChallengeOptions $opts = null): array
    {
        $this->lazyCleanup();

        $challenge = [
            'c' => $opts?->challengeCount       ?? $this->config->challengeCount,
            's' => $opts?->challengeSize        ?? $this->config->challengeSize,
            'd' => $opts?->challengeDifficulty  ?? $this->config->challengeDifficulty,
        ];
        $format = $opts?->format ?? $this->config->format;
        if (!self::validParameters($challenge) || !in_array($format, [1, 2], true)) {
            throw new \InvalidArgumentException('Invalid challenge parameters: count 1..1000, size 1..256, difficulty 1..16, format 1 or 2');
        }
        $expiresMs = $opts?->expiresMs ?? $this->config->expiresMs;
        $expires   = $this->nowMs() + $expiresMs;

        $token = $this->randomHex(25);
        $stored = $challenge + ['expires' => $expires];
        if ($this->config->scope !== null) {
            $stored['scope'] = $this->config->scope;
        }
        $result = ['challenge' => $challenge, 'token' => $token, 'expires' => $expires];
        $instrumentation = $opts?->instrumentation ?? $this->config->instrumentation;
        $behavior = $opts?->behavior ?? $this->config->behavior;
        if ($behavior !== false) {
            $behavior = $behavior === true ? new BehaviorOptions() : $behavior;
            $stored['behavior'] = $behavior->policy();
            $result['behavior'] = true;
        }
        if ($instrumentation !== false || $behavior !== false) {
            $meta = Generator::generate($instrumentation instanceof InstrumentationOptions ? $instrumentation : new InstrumentationOptions(), $this->nowMs(), $expires, $behavior !== false, $behavior !== false && ($behavior->block || $behavior->blockAgents !== false));
            $result['instrumentation'] = $meta['instrumentation'];
            unset($meta['instrumentation']);
            $stored['instrumentation'] = $meta;
        }
        if ($format === 2) {
            $proofs = [];
            $public = [];
            for ($i = 0; $i < $challenge['c']; $i++) {
                // Keep this library's size in hex characters in both formats. Upstream's
                // format-2 byte interpretation makes its default salt too long for its WASM solver.
                $proof = ['salt' => substr($this->randomHex((int)ceil($challenge['s'] / 2)), 0, $challenge['s']), 'target' => str_repeat('0', $challenge['d'])];
                $proofs[] = $proof;
                $public[] = ['protocol' => 'sha256-pow', 'payload' => $proof];
            }
            if (isset($result['instrumentation'])) {
                $public[] = ['protocol' => 'instrumentation', 'payload' => ['blob' => $result['instrumentation']]];
            }
            unset($result['challenge'], $result['instrumentation']);
            $result['format'] = 2;
            $result['challenges'] = $public;
            $stored['format'] = 2;
            $stored['proofs'] = $proofs;
        }
        if ($opts?->store === false) {
            unset($result['token']);
        } else {
            $this->config->challengeStorage->storeChallenge($token, $stored);
        }

        return $result;
    }

    /**
     * Verify solutions against a stored challenge and issue a verification token.
     *
     * @param array $solutions Integer nonces (format 1), or solution objects (format 2).
     * @return array<string, mixed>
     */
    public function redeemChallenge(string $token, array $solutions, array $instrumentation = [], array $requestHeaders = []): array
    {
        foreach ($solutions as $s) {
            if (!is_int($s) && !(is_array($s) && ($s === [] || !array_is_list($s)))) {
                return ['success' => false, 'message' => 'Invalid body'];
            }
        }
        if ($token === '' || !array_is_list($solutions)) {
            return ['success' => false, 'message' => 'Invalid body'];
        }
        // Guard records share storage but have their own prefixes; never read or delete them here.
        if (!preg_match('/^[a-f0-9]{50}$/D', $token)) {
            return ['success' => false, 'message' => 'Challenge invalid or expired'];
        }

        $this->lazyCleanup();

        $storage = $this->config->challengeStorage;
        if ($storage instanceof AtomicChallengeStorageInterface) {
            $data = $storage->consumeChallenge($token);
        } else {
            $data = $storage->readChallenge($token);
            $storage->deleteChallenge($token);
        }

        if ($data === null || ($data['expires'] ?? 0) < $this->nowMs() || !self::validParameters($data)) {
            return ['success' => false, 'message' => 'Challenge invalid or expired'];
        }
        if (($data['scope'] ?? null) !== $this->config->scope) {
            return ['success' => false, 'message' => 'Challenge token does not match scope', 'reason' => 'scope_mismatch'];
        }

        $count = $data['c'];
        $size  = $data['s'];
        $diff  = $data['d'];

        $format = $data['format'] ?? 1;
        if (count($solutions) !== $count + ($format === 2 && isset($data['instrumentation']) ? 1 : 0)) {
            return ['success' => false, 'message' => 'Invalid solution'];
        }

        for ($i = 1; $i <= $count; $i++) {
            if ($format === 2) {
                $salt = $data['proofs'][$i - 1]['salt'];
                $target = $data['proofs'][$i - 1]['target'];
                $nonce = is_array($solutions[$i - 1]) ? ($solutions[$i - 1]['nonce'] ?? null) : null;
                if (!is_string($nonce) && !self::safeNonce($nonce)) {
                    return ['success' => false, 'message' => 'Invalid solution'];
                }
            } else {
                $salt = Prng::generate($token . $i, $size);
                $target = Prng::generate($token . $i . 'd', $diff);
                $nonce = $solutions[$i - 1];
                if (!self::safeNonce($nonce)) {
                    return ['success' => false, 'message' => 'Invalid body'];
                }
            }
            $hash = hash('sha256', $salt . (string)$nonce);
            if (!str_starts_with($hash, $target)) {
                return ['success' => false, 'message' => 'Invalid solution'];
            }
        }

        $details = [];
        if (isset($data['instrumentation'])) {
            if ($format === 2) {
                $solution = $solutions[$count];
                if (!is_array($solution)) {
                    return ['success' => false, 'message' => 'Invalid solution'];
                }
                $instrumentation = ['instr' => $solution['instr'] ?? null, 'instr_blocked' => $solution['blocked'] ?? null, 'instr_error' => $solution['error'] ?? null, 'instr_timeout' => $solution['timeout'] ?? null];
            }
            $verified = $this->verifyInstrumentation($data['instrumentation'], $instrumentation);
            if (!$verified['valid']) {
                return ['success' => false, 'message' => 'Blocked by instrumentation', 'error' => 'Blocked by instrumentation', 'instr_error' => true, 'reason' => $verified['reason']]
                    + (isset($verified['blockedBy']) ? ['blockedBy' => $verified['blockedBy']] : []);
            }
            $details['riskFlags'] = $verified['riskFlags'];
        }
        if (isset($verified) || $requestHeaders) {
            $policy = Policy::evaluate($verified ?? [], $data['behavior'] ?? null, $requestHeaders, $this->config->behaviorReplayCheck, $data['expires'] - $this->nowMs());
            if (!$policy['valid']) {
                unset($policy['valid']);
                return ['success' => false, 'message' => 'Blocked by interaction checks', 'error' => 'Blocked by interaction checks'] + $policy;
            }
            unset($policy['valid']);
            $details = $policy;
        }

        $vertoken = $this->randomHex(15);
        $id       = $this->randomHex(8);
        $expires  = $this->nowMs() + $this->config->tokenTtlMs;
        $key      = $this->tokenKey($id, $vertoken);

        $this->config->tokenStorage->storeToken($key, $expires);

        return ['success' => true, 'token' => $id . ':' . $vertoken, 'expires' => $expires] + $details;
    }

    /** Redeem an unmodified widget JSON body, including instrumentation failure markers. */
    public function redeemChallengeBody(array $body, array $requestHeaders = []): array
    {
        if (!is_string($body['token'] ?? null) || !is_array($body['solutions'] ?? null)) {
            return ['success' => false, 'message' => 'Invalid body'];
        }
        return $this->redeemChallenge($body['token'], $body['solutions'], $body, $requestHeaders);
    }

    private function verifyInstrumentation(array $meta, array $body): array
    {
        $reason = null;
        if (($body['instr_blocked'] ?? null) === true) {
            $reason = $meta['blockAutomatedBrowsers'] ? 'instr_automated_browser' : 'instr_failed';
        } elseif (is_string($body['instr_error'] ?? null)) {
            $reason = [
                'csp_blocked' => 'instr_csp_blocked', 'csp_script_blocked' => 'instr_csp_blocked',
                'natives_tampered' => 'instr_natives_tampered', 'env_failed' => 'instr_env_failed', 'script_failed' => 'instr_script_failed',
            ][$body['instr_error']] ?? 'instr_failed';
        } elseif (($body['instr_timeout'] ?? null) === true) {
            $reason = 'instr_timeout';
        } elseif (!isset($body['instr'])) {
            $reason = 'instr_missing';
        }
        return $reason !== null ? ['valid' => false, 'reason' => $reason] : Verifier::verify($meta, $body['instr'], $this->nowMs());
    }

    /**
     * Validate a verification token returned from redeemChallenge.
     * By default, the token is consumed (deleted) on successful validation.
     */
    public function validateToken(string $token, bool $keepToken = false): bool
    {
        $this->lazyCleanup();

        if (!preg_match('/^[a-f0-9]{16}:[a-f0-9]{30}$/D', $token)) {
            return false;
        }
        $parts = explode(':', $token, 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return false;
        }
        [$id, $vertoken] = $parts;

        $key     = $this->tokenKey($id, $vertoken);
        $storage = $this->config->tokenStorage;
        $consumed = !$keepToken && $storage instanceof AtomicTokenStorageInterface;
        $expires = $consumed ? $storage->consumeToken($key) : $storage->readToken($key);

        if ($expires === null || $expires <= $this->nowMs()) {
            return false;
        }

        if (!$keepToken && !$consumed) {
            $storage->deleteToken($key);
        }
        return true;
    }

    /**
     * Manually run cleanup of expired challenges and tokens.
     */
    public function cleanup(): void
    {
        $this->config->challengeStorage->deleteExpiredChallenges();
        $this->config->tokenStorage->deleteExpiredTokens();
        $this->lastCleanupMs = $this->nowMs();
    }

    private function lazyCleanup(): void
    {
        if ($this->config->disableAutoCleanup) {
            return;
        }
        $now = $this->nowMs();
        if ($now - $this->lastCleanupMs > self::CLEANUP_INTERVAL_MS) {
            $this->cleanup();
        }
    }

    private function nowMs(): int
    {
        return (int)(microtime(true) * 1000);
    }

    private function randomHex(int $bytes): string
    {
        return bin2hex(random_bytes($bytes));
    }

    private static function safeNonce(mixed $nonce): bool
    {
        return is_int($nonce) && $nonce >= 0 && $nonce <= 9_007_199_254_740_991;
    }

    private static function validParameters(array $data): bool
    {
        return is_int($data['c'] ?? null) && $data['c'] >= 1 && $data['c'] <= 1000
            && is_int($data['s'] ?? null) && $data['s'] >= 1 && $data['s'] <= 256
            && is_int($data['d'] ?? null) && $data['d'] >= 1 && $data['d'] <= 16;
    }

    private function tokenKey(string $id, string $secret): string
    {
        $prefix = $this->config->scope === null ? '' : 'scope:' . hash('sha256', $this->config->scope) . ':';
        return $prefix . $id . ':' . hash('sha256', $secret);
    }
}
