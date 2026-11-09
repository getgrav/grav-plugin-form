<?php

declare(strict_types=1);

namespace TrilbyMedia\Cap\Storage;

/**
 * Storage for outstanding challenges and host records (token → arbitrary array with expires).
 * Challenges are typically short-lived (10 min default).
 */
interface ChallengeStorageInterface
{
    /**
     * @param array<string, mixed> $challenge Must contain expires (Unix milliseconds).
     */
    public function storeChallenge(string $token, array $challenge): void;

    /**
     * @return array<string, mixed>|null
     */
    public function readChallenge(string $token): ?array;

    public function deleteChallenge(string $token): void;

    public function deleteExpiredChallenges(): void;
}
