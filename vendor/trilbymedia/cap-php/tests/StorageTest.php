<?php

declare(strict_types=1);

namespace TrilbyMedia\Cap\Tests;

use PHPUnit\Framework\TestCase;
use TrilbyMedia\Cap\Storage\ArrayStorage;
use TrilbyMedia\Cap\Storage\FilesystemStorage;

final class StorageTest extends TestCase
{
    public function testArrayStorageChallengeRoundtrip(): void
    {
        $s = new ArrayStorage();
        $data = ['c' => 50, 's' => 32, 'd' => 4, 'expires' => PHP_INT_MAX];
        $s->storeChallenge('tok', $data);
        $this->assertSame($data, $s->readChallenge('tok'));
        $s->deleteChallenge('tok');
        $this->assertNull($s->readChallenge('tok'));
    }

    public function testArrayStorageTokenRoundtrip(): void
    {
        $s = new ArrayStorage();
        $s->storeToken('k', 12345);
        $this->assertSame(12345, $s->readToken('k'));
        $s->deleteToken('k');
        $this->assertNull($s->readToken('k'));
    }

    public function testArrayStorageDeleteExpired(): void
    {
        $s = new ArrayStorage();
        $nowMs = (int)(microtime(true) * 1000);
        $s->storeChallenge('fresh', ['c' => 1, 's' => 1, 'd' => 1, 'expires' => $nowMs + 60000]);
        $s->storeChallenge('stale', ['c' => 1, 's' => 1, 'd' => 1, 'expires' => $nowMs - 1000]);
        $s->deleteExpiredChallenges();
        $this->assertNotNull($s->readChallenge('fresh'));
        $this->assertNull($s->readChallenge('stale'));
    }

    public function testFilesystemStorageRoundtrip(): void
    {
        $dir = sys_get_temp_dir() . '/cap-php-test-' . bin2hex(random_bytes(4));
        try {
            $s = new FilesystemStorage($dir);
            $s->storeChallenge('t', ['c' => 1, 's' => 2, 'd' => 3, 'expires' => 999]);
            $this->assertSame(['c' => 1, 's' => 2, 'd' => 3, 'expires' => 999], $s->readChallenge('t'));

            $s->storeToken('k', 12345);
            $this->assertSame(12345, $s->readToken('k'));

            // Reopen to verify persistence:
            $s2 = new FilesystemStorage($dir);
            $this->assertSame(12345, $s2->readToken('k'));
            $this->assertNotNull($s2->readChallenge('t'));

            $s2->deleteToken('k');
            $this->assertNull($s2->readToken('k'));
        } finally {
            @unlink($dir . '/challenges.json');
            @unlink($dir . '/tokens.json');
            @rmdir($dir);
        }
    }
}
