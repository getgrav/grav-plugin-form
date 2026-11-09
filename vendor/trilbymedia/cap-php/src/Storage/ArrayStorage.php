<?php

declare(strict_types=1);

namespace TrilbyMedia\Cap\Storage;

/**
 * In-memory storage. Useful for tests and single-request flows.
 * Not persistent across requests; do not use in production.
 */
final class ArrayStorage implements AtomicChallengeStorageInterface, AtomicTokenStorageInterface
{
    /** @var array<string, array<string, mixed>> */
    private array $challenges = [];

    /** @var array<string, int> */
    private array $tokens = [];

    public function storeChallenge(string $token, array $challenge): void
    {
        $this->challenges[$token] = $challenge;
    }

    public function readChallenge(string $token): ?array
    {
        return $this->challenges[$token] ?? null;
    }

    public function deleteChallenge(string $token): void
    {
        unset($this->challenges[$token]);
    }

    public function consumeChallenge(string $token): ?array
    {
        $data = $this->readChallenge($token);
        $this->deleteChallenge($token);
        return $data;
    }

    public function deleteExpiredChallenges(): void
    {
        $now = (int)(microtime(true) * 1000);
        foreach ($this->challenges as $k => $v) {
            if ($v['expires'] < $now) {
                unset($this->challenges[$k]);
            }
        }
    }

    public function storeToken(string $key, int $expiresMs): void
    {
        $this->tokens[$key] = $expiresMs;
    }

    public function readToken(string $key): ?int
    {
        return $this->tokens[$key] ?? null;
    }

    public function deleteToken(string $key): void
    {
        unset($this->tokens[$key]);
    }

    public function consumeToken(string $key): ?int
    {
        $expires = $this->readToken($key);
        $this->deleteToken($key);
        return $expires;
    }

    public function deleteExpiredTokens(): void
    {
        $now = (int)(microtime(true) * 1000);
        foreach ($this->tokens as $k => $v) {
            if ($v < $now) {
                unset($this->tokens[$k]);
            }
        }
    }
}
