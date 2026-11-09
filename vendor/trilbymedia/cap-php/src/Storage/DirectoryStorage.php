<?php

declare(strict_types=1);

namespace TrilbyMedia\Cap\Storage;

/**
 * File-backed storage with one file per record, for sites without a shared cache server.
 *
 * Every record is its own file (`c/` for challenges, `t/` for tokens, sharded by the first
 * two characters of the key's SHA-256), so no request ever waits on another one's records.
 * Taking a record renames its file first: only one process can rename a given file, so a
 * challenge or token is used exactly once however many requests arrive at the same moment.
 *
 * A record file's modification time is set to its expiry, so the sweep of expired records
 * skips live ones without opening them. The sweep runs at most once per `$sweepInterval`
 * seconds per record kind, however often Cap asks for it.
 *
 * Keep the directory private, outside the web root.
 */
final class DirectoryStorage implements AtomicChallengeStorageInterface, AtomicTokenStorageInterface
{
    private const RECORD_NAME = '/^[a-f0-9]{64}$/D';
    /** Leftover temporary and claimed files older than this are removed by the sweep. */
    private const LEFTOVER_SECONDS = 60;
    /** Record mtimes are capped this far in the future (records with "never" expiry). */
    private const MAX_MTIME_AHEAD = 315_360_000; // 10 years

    private string $directory;

    public function __construct(string $directory, private int $sweepInterval = 300)
    {
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException("Cannot create storage directory: {$directory}");
        }
        $this->directory = rtrim($directory, '/\\');
    }

    public function storeChallenge(string $token, array $challenge): void
    {
        $json = json_encode($challenge, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $this->write($this->path('c', $token), $json, (int)($challenge['expires'] ?? 0));
    }

    public function readChallenge(string $token): ?array
    {
        return self::decodeChallenge($this->read($this->path('c', $token)));
    }

    public function deleteChallenge(string $token): void
    {
        @unlink($this->path('c', $token));
    }

    public function consumeChallenge(string $token): ?array
    {
        return self::decodeChallenge($this->take($this->path('c', $token)));
    }

    public function deleteExpiredChallenges(): void
    {
        $this->sweep('c', static fn (string $raw): int => (int)(self::decodeChallenge($raw)['expires'] ?? 0));
    }

    public function storeToken(string $key, int $expiresMs): void
    {
        $this->write($this->path('t', $key), (string)$expiresMs, $expiresMs);
    }

    public function readToken(string $key): ?int
    {
        return self::decodeToken($this->read($this->path('t', $key)));
    }

    public function deleteToken(string $key): void
    {
        @unlink($this->path('t', $key));
    }

    public function consumeToken(string $key): ?int
    {
        return self::decodeToken($this->take($this->path('t', $key)));
    }

    public function deleteExpiredTokens(): void
    {
        $this->sweep('t', static fn (string $raw): int => self::decodeToken($raw) ?? 0);
    }

    private function path(string $kind, string $key): string
    {
        $hash = hash('sha256', $key);

        return "{$this->directory}/{$kind}/" . substr($hash, 0, 2) . "/{$hash}";
    }

    private function write(string $path, string $data, int $expiresMs): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create storage directory: {$dir}");
        }

        // Write aside and rename into place, so readers never see a half-written record.
        $tmp = $dir . '/.tmp-' . bin2hex(random_bytes(6));
        try {
            if (@file_put_contents($tmp, $data) !== strlen($data)) {
                throw new \RuntimeException("Cannot write storage: {$path}");
            }
            $mtime = min(intdiv(max(0, $expiresMs), 1000) + 1, time() + self::MAX_MTIME_AHEAD);
            @touch($tmp, $mtime);
            if (!@rename($tmp, $path)) {
                throw new \RuntimeException("Cannot write storage: {$path}");
            }
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    private function read(string $path): ?string
    {
        $raw = @file_get_contents($path);

        return $raw === false ? null : $raw;
    }

    /**
     * Read and remove a record. Of several processes taking the same record at once,
     * exactly one gets it: the others find the file already renamed away.
     */
    private function take(string $path): ?string
    {
        $claimed = $path . '.' . bin2hex(random_bytes(6));
        if (!@rename($path, $claimed)) {
            return null;
        }
        $raw = @file_get_contents($claimed);
        @unlink($claimed);

        return $raw === false ? null : $raw;
    }

    /**
     * @param \Closure(string): int $expiresOf Expiry in milliseconds from a record's contents
     */
    private function sweep(string $kind, \Closure $expiresOf): void
    {
        $base = "{$this->directory}/{$kind}";
        if (!is_dir($base)) {
            return;
        }

        $marker = "{$this->directory}/.swept-{$kind}";
        clearstatcache(true, $marker);
        $last = @filemtime($marker);
        if ($last !== false && time() - $last < $this->sweepInterval) {
            return;
        }
        // Concurrent sweeps are harmless, only redundant, so the marker needs no lock.
        @touch($marker);

        $now = time();
        $nowMs = (int)(microtime(true) * 1000);
        foreach (@scandir($base) ?: [] as $shard) {
            if ($shard === '.' || $shard === '..' || !is_dir("{$base}/{$shard}")) {
                continue;
            }
            foreach (@scandir("{$base}/{$shard}") ?: [] as $name) {
                $file = "{$base}/{$shard}/{$name}";
                if ($name === '.' || $name === '..') {
                    continue;
                }
                if (!preg_match(self::RECORD_NAME, $name)) {
                    // A temporary or claimed file a crashed request left behind.
                    $ctime = @filectime($file);
                    if ($ctime !== false && $ctime < $now - self::LEFTOVER_SECONDS) {
                        @unlink($file);
                    }
                    continue;
                }
                $mtime = @filemtime($file);
                if ($mtime === false || $mtime > $now) {
                    continue;
                }
                // The mtime says expired (or couldn't be set); the contents decide.
                $raw = $this->read($file);
                if ($raw !== null && $expiresOf($raw) < $nowMs) {
                    @unlink($file);
                }
            }
        }
    }

    private static function decodeChallenge(?string $raw): ?array
    {
        if ($raw === null) {
            return null;
        }
        $decoded = json_decode($raw, true);

        // A damaged record reads as missing, which refuses it.
        return is_array($decoded) ? $decoded : null;
    }

    private static function decodeToken(?string $raw): ?int
    {
        return ($raw !== null && preg_match('/^\d{1,19}$/D', $raw)) ? (int)$raw : null;
    }
}
