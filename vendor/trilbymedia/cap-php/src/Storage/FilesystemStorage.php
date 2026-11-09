<?php

declare(strict_types=1);

namespace TrilbyMedia\Cap\Storage;

/**
 * File-backed storage. Mutations and consumption use per-file advisory locks.
 * Keep the directory private, outside the web root. All writers must use this class.
 * For large deployments use an atomic cache adapter rather than whole-file JSON.
 */
final class FilesystemStorage implements AtomicChallengeStorageInterface, AtomicTokenStorageInterface
{
    private string $challengesFile;
    private string $tokensFile;

    public function __construct(string $directory)
    {
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException("Cannot create storage directory: {$directory}");
        }
        $this->challengesFile = rtrim($directory, '/') . '/challenges.json';
        $this->tokensFile = rtrim($directory, '/') . '/tokens.json';
    }

    public function storeChallenge(string $token, array $challenge): void
    {
        $this->mutate($this->challengesFile, static function (array &$all) use ($token, $challenge): void {
            $all[$token] = $challenge;
        });
    }

    public function readChallenge(string $token): ?array
    {
        $all = $this->read($this->challengesFile);
        return $all[$token] ?? null;
    }

    public function deleteChallenge(string $token): void
    {
        $this->consumeChallenge($token);
    }

    public function consumeChallenge(string $token): ?array
    {
        return $this->consume($this->challengesFile, $token);
    }

    public function deleteExpiredChallenges(): void
    {
        $this->expire($this->challengesFile, true);
    }

    public function storeToken(string $key, int $expiresMs): void
    {
        $this->mutate($this->tokensFile, static function (array &$all) use ($key, $expiresMs): void {
            $all[$key] = $expiresMs;
        });
    }

    public function readToken(string $key): ?int
    {
        $all = $this->read($this->tokensFile);
        return isset($all[$key]) ? (int)$all[$key] : null;
    }

    public function deleteToken(string $key): void
    {
        $this->consumeToken($key);
    }

    public function consumeToken(string $key): ?int
    {
        $value = $this->consume($this->tokensFile, $key);
        return $value === null ? null : (int)$value;
    }

    public function deleteExpiredTokens(): void
    {
        $this->expire($this->tokensFile, false);
    }

    private function consume(string $file, string $key): mixed
    {
        return $this->mutate($file, static function (array &$all) use ($key): mixed {
            $value = $all[$key] ?? null;
            unset($all[$key]);
            return $value;
        });
    }

    private function expire(string $file, bool $challenges): void
    {
        $now = (int)(microtime(true) * 1000);
        $this->mutate($file, static function (array &$all) use ($now, $challenges): void {
            foreach ($all as $key => $value) {
                if (($challenges ? ($value['expires'] ?? 0) : (int)$value) < $now) {
                    unset($all[$key]);
                }
            }
        });
    }

    private function mutate(string $file, \Closure $operation): mixed
    {
        // A separate stable lock file is necessary because data files are replaced by rename.
        $lock = @fopen($file . '.lock', 'c');
        if ($lock === false) {
            throw new \RuntimeException("Cannot open storage lock: {$file}.lock");
        }
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new \RuntimeException("Cannot lock storage: {$file}");
            }
            $all = $this->read($file);
            $before = $all;
            $result = $operation($all);
            if ($all !== $before) {
                $this->write($file, $all);
            }
            return $result;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function read(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }
        $contents = @file_get_contents($file);
        if ($contents === false) {
            throw new \RuntimeException("Cannot read storage: {$file}");
        }
        // Fail closed on corrupt storage; do not silently overwrite outstanding records.
        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \RuntimeException("Invalid storage data: {$file}");
        }
        return $decoded;
    }

    private function write(string $file, array $data): void
    {
        $tmp = $file . '.tmp' . bin2hex(random_bytes(4));
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        try {
            if (@file_put_contents($tmp, $json) !== strlen($json) || !@rename($tmp, $file)) {
                throw new \RuntimeException("Cannot write storage: {$file}");
            }
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }
}
