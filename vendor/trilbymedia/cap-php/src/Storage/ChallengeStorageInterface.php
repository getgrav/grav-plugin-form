<?php

declare(strict_types=1);

namespace TrilbyMedia\Cap\Storage;

/**
 * Storage for outstanding challenges (token → {c, s, d, expires}).
 * Challenges are typically short-lived (10 min default).
 */
interface ChallengeStorageInterface
{
    /**
     * @param array{c:int,s:int,d:int,expires:int} $challenge
     */
    public function storeChallenge(string $token, array $challenge): void;

    /**
     * @return array{c:int,s:int,d:int,expires:int}|null
     */
    public function readChallenge(string $token): ?array;

    public function deleteChallenge(string $token): void;

    public function deleteExpiredChallenges(): void;
}
