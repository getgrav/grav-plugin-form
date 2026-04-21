<?php

declare(strict_types=1);

namespace TrilbyMedia\Cap\Storage;

/**
 * File-backed storage. Challenges and tokens each live in their own JSON file.
 * Simple and dependency-free; good default for small deployments.
 *
 * Not optimized for concurrency — use Psr16Storage with a real cache
 * (APCu, Redis, etc.) for production workloads.
 */
final class FilesystemStorage implements ChallengeStorageInterface, TokenStorageInterface
{
    private string $challengesFile;
    private string $tokensFile;

    public function __construct(string $directory)
    {
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException("Cannot create storage directory: {$directory}");
        }
        $this->challengesFile = rtrim($directory, '/') . '/challenges.json';
        $this->tokensFile     = rtrim($directory, '/') . '/tokens.json';
    }

    public function storeChallenge(string $token, array $challenge): void
    {
        $all = $this->read($this->challengesFile);
        $all[$token] = $challenge;
        $this->write($this->challengesFile, $all);
    }

    public function readChallenge(string $token): ?array
    {
        $all = $this->read($this->challengesFile);
        /** @var array{c:int,s:int,d:int,expires:int}|null */
        return $all[$token] ?? null;
    }

    public function deleteChallenge(string $token): void
    {
        $all = $this->read($this->challengesFile);
        if (isset($all[$token])) {
            unset($all[$token]);
            $this->write($this->challengesFile, $all);
        }
    }

    public function deleteExpiredChallenges(): void
    {
        $now = (int)(microtime(true) * 1000);
        $all = $this->read($this->challengesFile);
        $changed = false;
        foreach ($all as $k => $v) {
            if (($v['expires'] ?? 0) < $now) {
                unset($all[$k]);
                $changed = true;
            }
        }
        if ($changed) {
            $this->write($this->challengesFile, $all);
        }
    }

    public function storeToken(string $key, int $expiresMs): void
    {
        $all = $this->read($this->tokensFile);
        $all[$key] = $expiresMs;
        $this->write($this->tokensFile, $all);
    }

    public function readToken(string $key): ?int
    {
        $all = $this->read($this->tokensFile);
        return isset($all[$key]) ? (int)$all[$key] : null;
    }

    public function deleteToken(string $key): void
    {
        $all = $this->read($this->tokensFile);
        if (isset($all[$key])) {
            unset($all[$key]);
            $this->write($this->tokensFile, $all);
        }
    }

    public function deleteExpiredTokens(): void
    {
        $now = (int)(microtime(true) * 1000);
        $all = $this->read($this->tokensFile);
        $changed = false;
        foreach ($all as $k => $v) {
            if ((int)$v < $now) {
                unset($all[$k]);
                $changed = true;
            }
        }
        if ($changed) {
            $this->write($this->tokensFile, $all);
        }
    }

    private function read(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }
        $contents = @file_get_contents($file);
        if ($contents === false || $contents === '') {
            return [];
        }
        $decoded = json_decode($contents, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function write(string $file, array $data): void
    {
        $tmp = $file . '.tmp' . bin2hex(random_bytes(4));
        file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        rename($tmp, $file);
    }
}
