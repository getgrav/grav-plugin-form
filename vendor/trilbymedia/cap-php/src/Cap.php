<?php

declare(strict_types=1);

namespace TrilbyMedia\Cap;

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
     * @return array{challenge: array{c:int,s:int,d:int}, token?: string, expires: int}
     */
    public function createChallenge(?ChallengeOptions $opts = null): array
    {
        $this->lazyCleanup();

        $challenge = [
            'c' => $opts?->challengeCount       ?? $this->config->challengeCount,
            's' => $opts?->challengeSize        ?? $this->config->challengeSize,
            'd' => $opts?->challengeDifficulty  ?? $this->config->challengeDifficulty,
        ];
        $expiresMs = $opts?->expiresMs ?? $this->config->expiresMs;
        $expires   = $this->nowMs() + $expiresMs;

        if ($opts?->store === false) {
            return ['challenge' => $challenge, 'expires' => $expires];
        }

        $token = $this->randomHex(25);
        $this->config->challengeStorage->storeChallenge($token, $challenge + ['expires' => $expires]);

        return ['challenge' => $challenge, 'token' => $token, 'expires' => $expires];
    }

    /**
     * Verify solutions against a stored challenge and issue a verification token.
     *
     * @param int[] $solutions
     * @return array{success: bool, token?: string, expires?: int, message?: string}
     */
    public function redeemChallenge(string $token, array $solutions): array
    {
        foreach ($solutions as $s) {
            if (!is_int($s)) {
                return ['success' => false, 'message' => 'Invalid body'];
            }
        }
        if ($token === '') {
            return ['success' => false, 'message' => 'Invalid body'];
        }

        $this->lazyCleanup();

        $data = $this->config->challengeStorage->readChallenge($token);
        $this->config->challengeStorage->deleteChallenge($token);

        if ($data === null || ($data['expires'] ?? 0) < $this->nowMs()) {
            return ['success' => false, 'message' => 'Challenge invalid or expired'];
        }

        $count = $data['c'];
        $size  = $data['s'];
        $diff  = $data['d'];

        if (count($solutions) < $count) {
            return ['success' => false, 'message' => 'Invalid solution'];
        }

        for ($i = 1; $i <= $count; $i++) {
            $salt   = Prng::generate($token . $i, $size);
            $target = Prng::generate($token . $i . 'd', $diff);
            $hash   = hash('sha256', $salt . (string)$solutions[$i - 1]);
            if (!str_starts_with($hash, $target)) {
                return ['success' => false, 'message' => 'Invalid solution'];
            }
        }

        $vertoken = $this->randomHex(15);
        $id       = $this->randomHex(8);
        $expires  = $this->nowMs() + $this->config->tokenTtlMs;
        $key      = $id . ':' . hash('sha256', $vertoken);

        $this->config->tokenStorage->storeToken($key, $expires);

        return ['success' => true, 'token' => $id . ':' . $vertoken, 'expires' => $expires];
    }

    /**
     * Validate a verification token returned from redeemChallenge.
     * By default, the token is consumed (deleted) on successful validation.
     */
    public function validateToken(string $token, bool $keepToken = false): bool
    {
        $this->lazyCleanup();

        if ($token === '' || !str_contains($token, ':')) {
            return false;
        }
        $parts = explode(':', $token, 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return false;
        }
        [$id, $vertoken] = $parts;

        $key     = $id . ':' . hash('sha256', $vertoken);
        $expires = $this->config->tokenStorage->readToken($key);

        if ($expires === null || $expires <= $this->nowMs()) {
            return false;
        }

        if (!$keepToken) {
            $this->config->tokenStorage->deleteToken($key);
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
}
