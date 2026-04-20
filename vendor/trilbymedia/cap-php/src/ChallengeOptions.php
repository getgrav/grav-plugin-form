<?php

declare(strict_types=1);

namespace TrilbyMedia\Cap;

/**
 * Per-call overrides for Cap::createChallenge(). Any field left null
 * inherits the value configured on the Cap instance.
 */
final class ChallengeOptions
{
    public function __construct(
        public readonly ?int $challengeCount = null,
        public readonly ?int $challengeSize = null,
        public readonly ?int $challengeDifficulty = null,
        public readonly ?int $expiresMs = null,
        public readonly ?bool $store = null,
    ) {}
}
