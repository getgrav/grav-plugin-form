<?php
namespace Grav\Plugin\Form\Captcha;

use Grav\Common\Grav;
use TrilbyMedia\Cap\Cap;
use TrilbyMedia\Cap\Config as CapConfig;
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

    /** @var FilesystemStorage|Psr16Storage|null */
    protected static $storage = null;

    /**
     * Shared storage for challenges, tokens and the guard's records. The backend is
     * chosen from plugin config: 'grav-cache' (PSR-16 via Grav cache) or 'filesystem'.
     *
     * @return FilesystemStorage|Psr16Storage
     */
    public static function getStorage()
    {
        if (self::$storage !== null) {
            return self::$storage;
        }

        $grav    = Grav::instance();
        $backend = $grav['config']->get('plugins.form.cap.storage', 'grav-cache');
        if ($backend === 'filesystem') {
            $dir = rtrim(GRAV_ROOT, '/') . '/user/data/cap';
            return self::$storage = new FilesystemStorage($dir);
        }

        return self::$storage = new Psr16Storage($grav['cache']->getSimpleCache());
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

        $capConfig = new CapConfig(
            challengeStorage:     $storage,
            tokenStorage:         $storage,
            challengeCount:       (int)($config['challenge_count']      ?? 50),
            challengeSize:        (int)($config['challenge_size']       ?? 32),
            challengeDifficulty:  (int)($config['challenge_difficulty'] ?? 4),
            expiresMs:            (int)($config['expires_ms']           ?? 600_000),
        );

        return self::$cap = new Cap($capConfig);
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
     * @param string $token     Challenge token
     * @param int[]  $solutions
     * @param string $scope     Form name, or '' for the shared endpoint
     */
    public static function redeemChallenge(string $token, array $solutions, string $scope): array
    {
        // Only a challenge token (50 hex characters) may reach storage: the guard keeps its own
        // records in the same storage, and redeem deletes whatever key it is given.
        if (!preg_match('/^[a-f0-9]{50}$/D', $token)) {
            return ['success' => false, 'message' => 'Challenge invalid or expired'];
        }

        // redeemChallenge() deletes the challenge, so read its cost first.
        $challenge = $token !== '' ? self::getStorage()->readChallenge($token) : null;
        $result = self::getCap()->redeemChallenge($token, $solutions);

        if (!empty($result['success']) && isset($result['token'], $result['expires'])) {
            $guard = self::getGuard();
            $guard->bind((string)$result['token'], $scope, $challenge ? $guard->levelOf($challenge) : 0, (int)$result['expires']);
        }

        return $result;
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
            $ok = self::getCap()->validateToken((string)$token);

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
