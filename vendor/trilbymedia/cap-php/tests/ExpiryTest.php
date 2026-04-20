<?php

declare(strict_types=1);

namespace TrilbyMedia\Cap\Tests;

use PHPUnit\Framework\TestCase;
use TrilbyMedia\Cap\Cap;
use TrilbyMedia\Cap\ChallengeOptions;
use TrilbyMedia\Cap\Config;
use TrilbyMedia\Cap\Storage\ArrayStorage;

final class ExpiryTest extends TestCase
{
    public function testExpiredChallengeCannotBeRedeemed(): void
    {
        $storage = new ArrayStorage();
        $cap = new Cap(new Config(
            challengeStorage: $storage,
            tokenStorage:     $storage,
            challengeCount:   1,
            challengeDifficulty: 1,
        ));

        // Force a challenge that is already expired:
        $challenge = $cap->createChallenge(new ChallengeOptions(expiresMs: -1));
        $this->assertArrayHasKey('token', $challenge);

        $result = $cap->redeemChallenge($challenge['token'], [0]);
        $this->assertFalse($result['success']);
        $this->assertSame('Challenge invalid or expired', $result['message']);
    }

    public function testExpiredTokenFailsValidation(): void
    {
        $storage = new ArrayStorage();
        // Manually seed an expired token.
        $storage->storeToken('abc:' . hash('sha256', 'def'), time() * 1000 - 1000);

        $cap = new Cap(new Config(
            challengeStorage: $storage,
            tokenStorage:     $storage,
        ));

        $this->assertFalse($cap->validateToken('abc:def'));
    }
}
