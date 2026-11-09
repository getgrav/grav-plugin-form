<?php
namespace Grav\Plugin\Form\Captcha;

use Grav\Common\Grav;
use Grav\Common\Security;
use Grav\Common\Uri;
use TrilbyMedia\Cap\ChallengeOptions;
use TrilbyMedia\Cap\Storage\AtomicChallengeStorageInterface;
use TrilbyMedia\Cap\Storage\ChallengeStorageInterface;

/**
 * Checks around Cap's proof of work that make it costly to flood a form. They live
 * here rather than in cap-php so the library stays wire-compatible with the official
 * widget.
 *
 * - Adaptive cost: every submission from one IP counts. Past `adaptive_free`
 *   submissions in `adaptive_window` seconds, each further one doubles the work in
 *   the challenges that IP gets, up to `adaptive_max_level` doublings. A token solved
 *   at a lower level than the IP is on now is refused, so tokens can't be stockpiled
 *   cheaply and spent later.
 * - Form binding: a token redeemed through one form's endpoint only works on that form.
 * - Minimum time: the form has to have been on the page for `min_time` seconds
 *   before it's sent.
 *
 * Records go in Cap's own challenge storage, so they expire and clean up the same
 * way challenges do, on any storage backend.
 */
class CapGuard
{
    private const RATE_PREFIX = 'grav-rate:';
    private const BIND_PREFIX = 'grav-bind:';

    /** @var ChallengeStorageInterface */
    private $storage;

    /** @var array */
    private $config;

    public function __construct(ChallengeStorageInterface $storage, array $config)
    {
        $this->storage = $storage;
        $this->config = $config;
    }

    // ---- Adaptive cost ------------------------------------------------------

    /**
     * The work level the current IP is on: 0 is the configured cost, each level doubles it.
     */
    public function level(): int
    {
        if (!$this->adaptiveEnabled()) {
            return 0;
        }

        $record = $this->storage->readChallenge($this->rateKey());
        $count = ($record !== null && ($record['expires'] ?? 0) > $this->nowMs()) ? (int)($record['n'] ?? 0) : 0;
        $free = max(0, (int)($this->config['adaptive_free'] ?? 10));
        $max = max(0, (int)($this->config['adaptive_max_level'] ?? 8));

        return $count < $free ? 0 : min($max, $count - $free + 1);
    }

    /**
     * Count one submission from the current IP.
     */
    public function recordSubmission(): void
    {
        if (!$this->adaptiveEnabled()) {
            return;
        }

        $key = $this->rateKey();
        $now = $this->nowMs();
        $record = $this->storage->readChallenge($key);
        if ($record === null || ($record['expires'] ?? 0) <= $now) {
            $window = max(60, (int)($this->config['adaptive_window'] ?? 600));
            $record = ['n' => 0, 'expires' => $now + $window * 1000];
        }
        $record['n'] = (int)($record['n'] ?? 0) + 1;

        $this->storage->storeChallenge($key, $record);
    }

    /**
     * Challenge options for the current IP's level, or null for the configured cost.
     * Each level doubles the work: levels 1-3 double the number of sub-challenges,
     * and every fourth level adds a hex digit of difficulty instead (16x).
     */
    public function challengeOptions(): ?ChallengeOptions
    {
        $level = $this->level();
        if ($level === 0) {
            return null;
        }

        return new ChallengeOptions(
            $this->baseCount() * (2 ** ($level % 4)),
            null,
            $this->baseDifficulty() + intdiv($level, 4)
        );
    }

    /**
     * The level a stored challenge was issued at, from its count and difficulty.
     */
    public function levelOf(array $challenge): int
    {
        $count = max(1, (int)($challenge['c'] ?? $this->baseCount()));
        $difficulty = (int)($challenge['d'] ?? $this->baseDifficulty());
        $level = 4 * ($difficulty - $this->baseDifficulty()) + (int)floor(log($count / $this->baseCount(), 2) + 1e-9);

        return max(0, $level);
    }

    // ---- Form binding -------------------------------------------------------

    /**
     * Remember which form a freshly redeemed token belongs to and the level it was solved at.
     *
     * @param string $token   The verification token cap-php returned ("id:vertoken")
     * @param string $scope   The form name, or '' for the shared endpoint
     * @param int    $level   The level of the challenge that was solved
     * @param int    $expires The token's expiry, in milliseconds
     */
    public function bind(string $token, string $scope, int $level, int $expires): void
    {
        $id = strstr($token, ':', true);
        if ($id === false || $id === '') {
            return;
        }

        $this->storage->storeChallenge(self::bindKey($id), [
            'scope' => hash('sha256', $scope),
            'level' => $level,
            'expires' => $expires,
        ]);
    }

    /**
     * Check a submitted token's binding. Returns an error code, or null when it passes.
     *
     * @param string      $token    The submitted token
     * @param string|null $scope    The form being submitted, or null to accept any form
     * @param int         $required The level the token has to have been solved at
     */
    public function checkToken(string $token, ?string $scope, int $required): ?string
    {
        $id = strstr($token, ':', true);
        if ($id === false || $id === '') {
            return 'invalid-or-expired-token';
        }

        $key = self::bindKey($id);
        if ($this->storage instanceof AtomicChallengeStorageInterface) {
            $record = $this->storage->consumeChallenge($key);
        } else {
            $record = $this->storage->readChallenge($key);
            $this->storage->deleteChallenge($key);
        }

        if ($record === null || ($record['expires'] ?? 0) <= $this->nowMs()) {
            return 'invalid-or-expired-token';
        }
        if ($scope !== null && !hash_equals((string)($record['scope'] ?? ''), hash('sha256', $scope))) {
            return 'token-for-another-form';
        }
        if ((int)($record['level'] ?? 0) < $required) {
            return 'token-below-required-level';
        }

        return null;
    }

    /**
     * URL-safe segment that names a form in the Cap endpoint path.
     */
    public static function scopeSegment(string $formName): string
    {
        return rtrim(strtr(base64_encode($formName), '+/', '-_'), '=');
    }

    /**
     * Form name from an endpoint path segment, or null if it isn't one.
     */
    public static function scopeFromSegment(string $segment): ?string
    {
        if ($segment === '' || strlen($segment) > 256 || !preg_match('/^[A-Za-z0-9_-]+$/', $segment)) {
            return null;
        }
        $name = base64_decode(strtr($segment, '-_', '+/'), true);

        return ($name === false || $name === '') ? null : $name;
    }

    // ---- Minimum time -------------------------------------------------------

    /**
     * Signed stamp of when a form was put on the page.
     */
    public static function stamp(string $formName): string
    {
        $time = (string)time();

        return $time . '.' . self::sign($time, $formName);
    }

    /**
     * Check a submitted stamp. Returns an error code, or null when it passes.
     */
    public function checkStamp(?string $stamp, string $formName): ?string
    {
        $minTime = (int)($this->config['min_time'] ?? 3);
        if ($minTime <= 0) {
            return null;
        }

        if (!is_string($stamp) || !preg_match('/^(\d{1,12})\.([a-f0-9]{32})$/', $stamp, $m)
            || !hash_equals(self::sign($m[1], $formName), $m[2])) {
            return 'invalid-stamp';
        }

        $age = time() - (int)$m[1];
        if ($age < 0) {
            return 'invalid-stamp';
        }

        return $age < $minTime ? 'submitted-too-fast' : null;
    }

    private static function sign(string $time, string $formName): string
    {
        return substr(hash_hmac('sha256', 'cap-stamp|' . $time . '|' . $formName, self::secret()), 0, 32);
    }

    private static function bindKey(string $id): string
    {
        return self::BIND_PREFIX . hash_hmac('sha256', $id, self::secret());
    }

    private static function secret(): string
    {
        // Grav 2.0 keeps its HMAC key outside the config tree; 1.7 has security.salt.
        if (method_exists(Security::class, 'getNonceKey')) {
            return Security::getNonceKey();
        }

        return (string)Grav::instance()['config']->get('security.salt', '');
    }

    // ---- Helpers ------------------------------------------------------------

    private function adaptiveEnabled(): bool
    {
        return (bool)($this->config['adaptive'] ?? true);
    }

    private function baseCount(): int
    {
        return max(1, (int)($this->config['challenge_count'] ?? 50));
    }

    private function baseDifficulty(): int
    {
        return max(1, (int)($this->config['challenge_difficulty'] ?? 4));
    }

    /**
     * Rate key for the current IP. IPv6 is counted per /64, since one host usually holds the whole block.
     */
    private function rateKey(): string
    {
        $ip = Uri::ip();
        $packed = @inet_pton($ip);
        if ($packed !== false && strlen($packed) === 16) {
            $ip = bin2hex(substr($packed, 0, 8)) . '::/64';
        }

        // Keyed with the site secret, so the key can't be worked out from outside.
        return self::RATE_PREFIX . hash_hmac('sha256', $ip, self::secret());
    }

    private function nowMs(): int
    {
        return (int)(microtime(true) * 1000);
    }
}
