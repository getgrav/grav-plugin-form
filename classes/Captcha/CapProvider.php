<?php
namespace Grav\Plugin\Form\Captcha;

use Grav\Common\Grav;
use TrilbyMedia\Cap\BehaviorOptions;
use TrilbyMedia\Cap\Cap;
use TrilbyMedia\Cap\Config as CapConfig;
use TrilbyMedia\Cap\InstrumentationOptions;
use TrilbyMedia\Cap\Storage\DirectoryStorage;
use TrilbyMedia\Cap\Storage\FilesystemStorage;
use TrilbyMedia\Cap\Storage\Psr16Storage;

/**
 * Cap (cap.js) proof-of-work captcha provider. Wire-compatible with
 * the official @cap.js/widget via the trilbymedia/cap-php library.
 */
class CapProvider implements CaptchaProviderInterface
{
    public const ENDPOINT_BASE     = '/forms-cap/';
    public const CHALLENGE_PATH    = '/forms-cap/challenge';
    public const REDEEM_PATH       = '/forms-cap/redeem';
    public const HIDDEN_FIELD_NAME = 'cap-token';
    public const STAMP_FIELD_NAME  = 'cap-stamp';

    /** @var array */
    protected $config;

    /** @var Cap|null */
    protected static $cap = null;

    public function __construct()
    {
        $this->config = Grav::instance()['config']->get('plugins.form.cap', []);
    }

    /** @var CapGuard|null */
    protected static $guard = null;

    /** @var DirectoryStorage|FilesystemStorage|Psr16Storage|null */
    protected static $storage = null;

    /**
     * Shared storage for challenges, tokens and the guard's records, from `cap.storage`:
     *
     * - `files` (default): one file per record in tmp/cap. Each challenge and token is
     *   used exactly once, even when several requests arrive at the same moment.
     * - `grav-cache`: Grav's cache. PSR-16 can't take a record atomically, so this is only
     *   used with a Redis or Memcached cache, which several servers can share. With a
     *   cache local to this server (file, APCu) it gains nothing, so `files` is used.
     * - `filesystem`: one JSON file in user/data/cap, rewritten under a lock on every change.
     *
     * @return DirectoryStorage|FilesystemStorage|Psr16Storage
     */
    public static function getStorage()
    {
        if (self::$storage !== null) {
            return self::$storage;
        }

        $grav    = Grav::instance();
        $backend = (string)$grav['config']->get('plugins.form.cap.storage', 'files');
        if ($backend === 'filesystem') {
            $dir = rtrim(GRAV_ROOT, '/') . '/user/data/cap';
            return self::$storage = new FilesystemStorage($dir);
        }

        $cache = $grav['cache'];
        $shared = $backend === 'grav-cache' && method_exists($cache, 'getDriverName')
            && in_array($cache->getDriverName(), ['redis', 'memcached', 'memcache'], true);

        // DirectoryStorage needs cap-php 1.2; whichever plugin's copy loads first wins.
        if (!$shared && class_exists(DirectoryStorage::class)) {
            $dir = $grav['locator']->findResource('tmp://', true, true) . '/cap';
            return self::$storage = new DirectoryStorage($dir);
        }

        return self::$storage = new Psr16Storage($cache->getSimpleCache());
    }

    /**
     * Get (or build) a shared Cap instance.
     */
    public static function getCap(): Cap
    {
        if (self::$cap !== null) {
            return self::$cap;
        }

        $config  = Grav::instance()['config']->get('plugins.form.cap', []);
        $storage = self::getStorage();

        $args = [
            'challengeStorage'    => $storage,
            'tokenStorage'        => $storage,
            'challengeCount'      => (int)($config['challenge_count']      ?? 50),
            'challengeSize'       => (int)($config['challenge_size']       ?? 32),
            'challengeDifficulty' => (int)($config['challenge_difficulty'] ?? 4),
            'expiresMs'           => (int)($config['expires_ms']           ?? 600_000),
        ];

        // Browser checks need cap-php 1.1. Another plugin (the API plugin) bundles cap-php too,
        // and whichever copy loads first wins; with an older one, Cap runs without them.
        if (self::supportsBrowserChecks()) {
            $behavior = (string)($config['behavior'] ?? 'off');
            if ((bool)($config['instrumentation'] ?? true)) {
                $args['instrumentation'] = new InstrumentationOptions(
                    blockAutomatedBrowsers: (bool)($config['block_automated_browsers'] ?? true),
                    detectAutomatedInteractions: $behavior !== 'off',
                    blockAutomatedInteractions: $behavior === 'block',
                );
            }
            if ($behavior === 'report' || $behavior === 'block') {
                $agents = (string)($config['block_agents'] ?? 'off');
                $args['behavior'] = new BehaviorOptions(
                    block: $behavior === 'block',
                    // Invisible Cap solves when the page loads, before anyone has touched the form.
                    requireInteraction: false,
                    blockAgents: in_array($agents, ['identified', 'all'], true) ? $agents : false,
                );
            }
        }

        return self::$cap = new Cap(new CapConfig(...$args));
    }

    /**
     * Whether the loaded cap-php has browser instrumentation (1.1+).
     */
    public static function supportsBrowserChecks(): bool
    {
        static $supported = null;
        if ($supported === null) {
            $supported = class_exists(InstrumentationOptions::class)
                && method_exists(Cap::class, 'redeemChallengeBody');
        }

        return $supported;
    }

    /**
     * Get (or build) the shared guard: adaptive cost, form binding and minimum time.
     */
    public static function getGuard(): CapGuard
    {
        if (self::$guard !== null) {
            return self::$guard;
        }

        return self::$guard = new CapGuard(
            self::getStorage(),
            Grav::instance()['config']->get('plugins.form.cap', [])
        );
    }

    /**
     * Issue a challenge at the cost the requesting IP is on.
     */
    public static function createChallenge(): array
    {
        return self::getCap()->createChallenge(self::getGuard()->challengeOptions());
    }

    /**
     * Redeem a solved challenge, and tie the token to the form it was requested for.
     *
     * @param array  $body  The widget's redeem body as sent: token, solutions and, with
     *                      browser checks on, their results
     * @param string $scope Form name, or '' for the shared endpoint
     * @return array The result, plus 'status' (HTTP status code) when it failed
     */
    public static function redeemChallenge(array $body, string $scope): array
    {
        $token = $body['token'] ?? null;
        $solutions = $body['solutions'] ?? null;
        if (!is_string($token) || !is_array($solutions)) {
            return ['success' => false, 'message' => 'Invalid body', 'status' => 400];
        }

        // Only a challenge token (50 hex characters) may reach storage: the guard keeps its own
        // records in the same storage, and redeem deletes whatever key it is given.
        if (!preg_match('/^[a-f0-9]{50}$/D', $token)) {
            return ['success' => false, 'message' => 'Challenge invalid or expired', 'status' => 403];
        }

        // redeemChallenge() deletes the challenge, so read its cost first.
        $challenge = self::getStorage()->readChallenge($token);
        $cap = self::getCap();
        if (self::supportsBrowserChecks()) {
            $behavior = (string)(Grav::instance()['config']->get('plugins.form.cap.behavior') ?? 'off');
            $result = $cap->redeemChallengeBody($body, $behavior !== 'off' ? self::requestHeaders() : []);
        } else {
            $result = $cap->redeemChallenge($token, $solutions);
        }

        if (!empty($result['success']) && isset($result['token'], $result['expires'])) {
            $guard = self::getGuard();
            $guard->bind((string)$result['token'], $scope, $challenge ? $guard->levelOf($challenge) : 0, (int)$result['expires']);
            if (!empty($result['riskFlags']) || isset($result['agent'])) {
                Grav::instance()['log']->info('Cap redeem allowed with risk flags: ' . json_encode(array_intersect_key($result, array_flip(['riskFlags', 'agent', 'behavior']))));
            }
            // The widget only needs the token; risk details stay on the server.
            return ['success' => true, 'token' => $result['token'], 'expires' => $result['expires']];
        }

        if (!empty($result['reason']) || !empty($result['instr_error'])) {
            Grav::instance()['log']->info('Cap redeem refused by browser checks: ' . json_encode(array_intersect_key($result, array_flip(['reason', 'blockedBy', 'riskFlags', 'agent']))));
        }
        $result['status'] = ($result['reason'] ?? '') === 'instr_timeout' ? 429 : 403;

        return $result;
    }

    /**
     * The request's headers, for the agent checks. Taken from the request, never from the body.
     *
     * @return array<string, string>
     */
    protected static function requestHeaders(): array
    {
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            if (is_array($headers)) {
                return $headers;
            }
        }
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (is_string($value) && strncmp($key, 'HTTP_', 5) === 0) {
                $headers[str_replace('_', '-', strtolower(substr($key, 5)))] = $value;
            }
        }

        return $headers;
    }

    /**
     * {@inheritdoc}
     */
    public function validate(array $form, array $params = []): array
    {
        $grav = Grav::instance();

        try {
            $token = $_POST[self::HIDDEN_FIELD_NAME] ?? $form[self::HIDDEN_FIELD_NAME] ?? null;

            if (!$token) {
                $grav['log']->warning('Cap validation failed: missing token');
                return [
                    'success' => false,
                    'error'   => 'missing-input-response',
                    'details' => ['error' => 'missing-input-response']
                ];
            }

            $guard = self::getGuard();
            $scope = isset($params['form_name']) ? (string)$params['form_name'] : null;

            // Every submission counts toward the IP's cost, whether it passes or not.
            $required = $guard->level();
            $guard->recordSubmission();

            // Form plugin forms carry a signed stamp from when the form was rendered.
            if ($scope !== null) {
                $stampError = $guard->checkStamp($_POST[self::STAMP_FIELD_NAME] ?? null, $scope);
                if ($stampError !== null) {
                    self::getCap()->validateToken((string)$token); // spend it anyway
                    return [
                        'success' => false,
                        'error'   => $stampError,
                        'details' => ['error' => $stampError]
                    ];
                }
            }

            $bindError = $guard->checkToken((string)$token, $scope, $required);
            // With no binding left, another request already took this token (or it never
            // existed), so leave the token to that request instead of spending it here.
            $ok = $bindError !== 'invalid-or-expired-token' && self::getCap()->validateToken((string)$token);

            if ($bindError !== null) {
                return [
                    'success' => false,
                    'error'   => 'validation-failed',
                    'details' => ['error' => $bindError, 'required_level' => $required]
                ];
            }

            if (!$ok) {
                return [
                    'success' => false,
                    'error'   => 'validation-failed',
                    'details' => ['error' => 'invalid-or-expired-token']
                ];
            }

            return ['success' => true];
        } catch (\Exception $e) {
            $grav['log']->error('Cap validation error: ' . $e->getMessage());
            return [
                'success' => false,
                'error'   => $e->getMessage(),
                'details' => ['exception' => get_class($e)]
            ];
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getClientProperties(string $formId, array $field): array
    {
        $mode = $field['mode'] ?? $this->config['mode'] ?? 'invisible';
        if (!in_array($mode, ['invisible', 'checkbox'], true)) {
            $mode = 'invisible';
        }

        return [
            'provider'         => 'cap',
            'mode'             => $mode,
            'endpoint'         => self::ENDPOINT_BASE . CapGuard::scopeSegment($formId) . '/',
            'hiddenFieldName'  => self::HIDDEN_FIELD_NAME,
            'containerId'      => "cap-{$formId}",
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getTemplateName(): string
    {
        return 'forms/fields/cap/cap.html.twig';
    }
}
