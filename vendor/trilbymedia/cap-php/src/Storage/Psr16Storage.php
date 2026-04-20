<?php

declare(strict_types=1);

namespace TrilbyMedia\Cap\Storage;

use Psr\SimpleCache\CacheInterface;

/**
 * Backs Cap storage with any PSR-16 cache (APCu, Redis, Memcached,
 * Grav cache, etc.). Recommended for production.
 *
 * Note: PSR-16 has no "list all keys" primitive, so deleteExpired*()
 * is a no-op — cache backends should evict via their own TTL.
 */
final class Psr16Storage implements ChallengeStorageInterface, TokenStorageInterface
{
    public function __construct(
        private CacheInterface $cache,
        private string $challengePrefix = 'cap_c_',
        private string $tokenPrefix     = 'cap_t_',
    ) {}

    public function storeChallenge(string $token, array $challenge): void
    {
        $ttl = max(1, (int)ceil(($challenge['expires'] - (int)(microtime(true) * 1000)) / 1000));
        $this->cache->set($this->key($this->challengePrefix, $token), $challenge, $ttl);
    }

    public function readChallenge(string $token): ?array
    {
        $val = $this->cache->get($this->key($this->challengePrefix, $token));
        return is_array($val) ? $val : null;
    }

    public function deleteChallenge(string $token): void
    {
        $this->cache->delete($this->key($this->challengePrefix, $token));
    }

    public function deleteExpiredChallenges(): void
    {
        // Cache backend handles expiry via TTL.
    }

    public function storeToken(string $key, int $expiresMs): void
    {
        $ttl = max(1, (int)ceil(($expiresMs - (int)(microtime(true) * 1000)) / 1000));
        $this->cache->set($this->key($this->tokenPrefix, $key), $expiresMs, $ttl);
    }

    public function readToken(string $key): ?int
    {
        $val = $this->cache->get($this->key($this->tokenPrefix, $key));
        return is_int($val) ? $val : null;
    }

    public function deleteToken(string $key): void
    {
        $this->cache->delete($this->key($this->tokenPrefix, $key));
    }

    public function deleteExpiredTokens(): void
    {
        // Cache backend handles expiry via TTL.
    }

    private function key(string $prefix, string $raw): string
    {
        // PSR-16 reserves some chars in keys. Colons are fine in most
        // implementations but we hash to be safe across the ecosystem.
        return $prefix . hash('sha256', $raw);
    }
}
