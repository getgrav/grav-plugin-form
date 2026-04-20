<?php

declare(strict_types=1);

namespace TrilbyMedia\Cap\Tests;

use PHPUnit\Framework\TestCase;
use TrilbyMedia\Cap\Cap;
use TrilbyMedia\Cap\ChallengeOptions;
use TrilbyMedia\Cap\Config;
use TrilbyMedia\Cap\Prng;
use TrilbyMedia\Cap\Storage\ArrayStorage;

final class CapTest extends TestCase
{
    /**
     * Brute-force a single sub-challenge the same way the widget does:
     * find an integer n such that sha256(salt . n) starts with target.
     */
    private static function solve(string $salt, string $target): int
    {
        for ($n = 0; $n < 10_000_000; $n++) {
            if (str_starts_with(hash('sha256', $salt . $n), $target)) {
                return $n;
            }
        }
        throw new \RuntimeException('No solution found — difficulty too high for test');
    }

    /**
     * @return int[]
     */
    private static function solveAll(string $token, int $count, int $size, int $diff): array
    {
        $solutions = [];
        for ($i = 1; $i <= $count; $i++) {
            $salt   = Prng::generate($token . $i, $size);
            $target = Prng::generate($token . $i . 'd', $diff);
            $solutions[] = self::solve($salt, $target);
        }
        return $solutions;
    }

    private function makeCap(int $count = 3, int $diff = 1): Cap
    {
        $storage = new ArrayStorage();
        return new Cap(new Config(
            challengeStorage: $storage,
            tokenStorage:     $storage,
            challengeCount:   $count,
            challengeDifficulty: $diff,
        ));
    }

    public function testRoundTrip(): void
    {
        $cap = $this->makeCap();

        $challenge = $cap->createChallenge();
        $this->assertArrayHasKey('token', $challenge);
        $this->assertSame(50, strlen($challenge['token'])); // 25 bytes hex
        $this->assertSame(3, $challenge['challenge']['c']);

        $solutions = self::solveAll(
            $challenge['token'],
            $challenge['challenge']['c'],
            $challenge['challenge']['s'],
            $challenge['challenge']['d'],
        );

        $redeem = $cap->redeemChallenge($challenge['token'], $solutions);
        $this->assertTrue($redeem['success']);
        $this->assertArrayHasKey('token', $redeem);
        $this->assertStringContainsString(':', $redeem['token']);

        $this->assertTrue($cap->validateToken($redeem['token']));
        // Token is single-use by default:
        $this->assertFalse($cap->validateToken($redeem['token']));
    }

    public function testKeepTokenAllowsReuse(): void
    {
        $cap = $this->makeCap();
        $challenge = $cap->createChallenge();
        $solutions = self::solveAll(
            $challenge['token'],
            $challenge['challenge']['c'],
            $challenge['challenge']['s'],
            $challenge['challenge']['d'],
        );
        $redeem = $cap->redeemChallenge($challenge['token'], $solutions);

        $this->assertTrue($cap->validateToken($redeem['token'], keepToken: true));
        $this->assertTrue($cap->validateToken($redeem['token'], keepToken: true));
        // Consume on next call
        $this->assertTrue($cap->validateToken($redeem['token']));
        $this->assertFalse($cap->validateToken($redeem['token']));
    }

    public function testWrongSolutionFails(): void
    {
        $cap = $this->makeCap();
        $challenge = $cap->createChallenge();
        $count = $challenge['challenge']['c'];

        $redeem = $cap->redeemChallenge($challenge['token'], array_fill(0, $count, 0));
        $this->assertFalse($redeem['success']);
        $this->assertSame('Invalid solution', $redeem['message']);
    }

    public function testChallengeConsumedOnRedeemEvenOnFailure(): void
    {
        $cap = $this->makeCap();
        $challenge = $cap->createChallenge();
        $count = $challenge['challenge']['c'];

        $first  = $cap->redeemChallenge($challenge['token'], array_fill(0, $count, 0));
        $second = $cap->redeemChallenge($challenge['token'], array_fill(0, $count, 0));

        $this->assertFalse($first['success']);
        $this->assertFalse($second['success']);
        $this->assertSame('Challenge invalid or expired', $second['message']);
    }

    public function testUnknownTokenFails(): void
    {
        $cap = $this->makeCap();
        $this->assertFalse($cap->validateToken('bogus:token'));
        $this->assertFalse($cap->validateToken(''));
        $this->assertFalse($cap->validateToken('no-colon'));
        $this->assertFalse($cap->validateToken(':empty-id'));
        $this->assertFalse($cap->validateToken('empty-vertoken:'));
    }

    public function testRedeemRejectsNonIntegerSolutions(): void
    {
        $cap = $this->makeCap();
        $challenge = $cap->createChallenge();
        $result = $cap->redeemChallenge($challenge['token'], [1, 'not-an-int', 3]);
        $this->assertFalse($result['success']);
        $this->assertSame('Invalid body', $result['message']);
    }

    public function testCreateChallengeStoreFalseOmitsToken(): void
    {
        $cap = $this->makeCap();
        $out = $cap->createChallenge(new ChallengeOptions(store: false));
        $this->assertArrayNotHasKey('token', $out);
        $this->assertArrayHasKey('challenge', $out);
        $this->assertArrayHasKey('expires', $out);
    }

    public function testChallengeDefaults(): void
    {
        $storage = new ArrayStorage();
        $cap = new Cap(new Config(
            challengeStorage: $storage,
            tokenStorage:     $storage,
        ));
        $c = $cap->createChallenge();
        $this->assertSame(50, $c['challenge']['c']);
        $this->assertSame(32, $c['challenge']['s']);
        $this->assertSame(4,  $c['challenge']['d']);
    }
}
