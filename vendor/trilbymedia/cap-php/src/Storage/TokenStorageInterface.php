<?php

declare(strict_types=1);

namespace TrilbyMedia\Cap\Storage;

/**
 * Storage for redeemed verification tokens (key → expiresMs).
 * Key is formatted "{id}:{sha256(vertoken)}".
 */
interface TokenStorageInterface
{
    public function storeToken(string $key, int $expiresMs): void;

    public function readToken(string $key): ?int;

    public function deleteToken(string $key): void;

    public function deleteExpiredTokens(): void;
}
