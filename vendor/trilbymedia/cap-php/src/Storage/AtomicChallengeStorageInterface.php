<?php

declare(strict_types=1);

namespace TrilbyMedia\Cap\Storage;

/** Optional atomic read-and-delete for concurrent replay protection. */
interface AtomicChallengeStorageInterface extends ChallengeStorageInterface
{
    public function consumeChallenge(string $token): ?array;
}
