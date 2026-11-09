<?php

declare(strict_types=1);

namespace TrilbyMedia\Cap;

use TrilbyMedia\Cap\Storage\ChallengeStorageInterface;
use TrilbyMedia\Cap\Storage\TokenStorageInterface;

/**
 * Immutable configuration for a Cap instance.
 */
final class Config
{
    public function __construct(
        public readonly ChallengeStorageInterface $challengeStorage,
        public readonly TokenStorageInterface $tokenStorage,
        public readonly int $challengeCount = 50,
        public readonly int $challengeSize = 32,
        public readonly int $challengeDifficulty = 4,
        public readonly int $expiresMs = 600_000,
        public readonly int $tokenTtlMs = 1_200_000,
        public readonly bool $disableAutoCleanup = false,
        public readonly bool|InstrumentationOptions $instrumentation = false,
        public readonly bool|BehaviorOptions $behavior = false,
        public readonly ?\Closure $behaviorReplayCheck = null,
        public readonly int $format = 1,
        public readonly ?string $scope = null,
    ) {}
}
