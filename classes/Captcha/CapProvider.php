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

    /** @var array */
    protected $config;

    /** @var Cap|null */
    protected static $cap = null;

    public function __construct()
    {
        $this->config = Grav::instance()['config']->get('plugins.form.cap', []);
    }

    /**
     * Get (or build) a shared Cap instance. Storage backend is chosen from
     * plugin config: 'grav-cache' (PSR-16 via Grav cache) or 'filesystem'.
     */
    public static function getCap(): Cap
    {
        if (self::$cap !== null) {
            return self::$cap;
        }

        $grav   = Grav::instance();
        $config = $grav['config']->get('plugins.form.cap', []);

        $backend = $config['storage'] ?? 'grav-cache';
        if ($backend === 'filesystem') {
            $dir = rtrim(GRAV_ROOT, '/') . '/user/data/cap';
            $storage = new FilesystemStorage($dir);
        } else {
            $storage = new Psr16Storage($grav['cache']->getSimpleCache());
        }

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

            $ok = self::getCap()->validateToken((string)$token);

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
            'endpoint'         => self::ENDPOINT_BASE,
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
