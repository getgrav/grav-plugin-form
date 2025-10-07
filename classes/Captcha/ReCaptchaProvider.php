<?php
namespace Grav\Plugin\Form\Captcha;

use Grav\Common\Grav;
use Grav\Common\Uri;

/**
 * Google reCAPTCHA provider implementation
 */
class ReCaptchaProvider implements CaptchaProviderInterface
{
    /** @var array */
    protected $config;

    public function __construct()
    {
        $this->config = Grav::instance()['config']->get('plugins.form.recaptcha', []);
    }

    /**
     * {@inheritdoc}
     */
    public function validate(array $form, array $params = []): array
    {
        $grav = Grav::instance();
        $uri = $grav['uri'];
        $ip = Uri::ip();
        $hostname = $uri->host();

        try {
            $secretKey = $params['recaptcha_secret'] ?? $params['recatpcha_secret'] ??
                      $this->config['secret_key'] ?? null;

            $defaultVersion = $this->normalizeVersion($this->config['version'] ?? '2-checkbox');
            $version = $this->normalizeVersion($params['recaptcha_version'] ?? $defaultVersion);

            $payloadVersion = $this->detectVersionFromPayload($form);
            if ($payloadVersion !== null) {
                $version = $payloadVersion;
            }

            if (!$secretKey) {
                throw new \RuntimeException("reCAPTCHA secret key not configured.");
            }

            $requestMethod = extension_loaded('curl') ? new \ReCaptcha\RequestMethod\CurlPost() : null;
            $recaptcha = new \ReCaptcha\ReCaptcha($secretKey, $requestMethod);

            // Handle V3
            if ($version === '3') {
                // For V3, look for token in both top level and data[] structure
                $token = $form['token'] ?? ($form['data']['token'] ?? null);
                $action = $form['action'] ?? ($form['data']['action'] ?? null);

                if (!$token) {
                    $grav['log']->debug('reCAPTCHA validation failed: token missing for v3');
                    return [
                        'success' => false,
                        'error' => 'missing-input-response',
                        'details' => ['error' => 'missing-input-response', 'version' => 'v3']
                    ];
                }

                $recaptcha->setExpectedHostname($hostname);

                // Set action if provided
                if ($action) {
                    $recaptcha->setExpectedAction($action);
                }

                // Set score threshold
                $recaptcha->setScoreThreshold($this->config['score_threshold'] ?? 0.5);
            }
            // Handle V2 (both checkbox and invisible)
            else {
                // For V2, look for standard response parameter
                $token = $form['g-recaptcha-response'] ?? ($form['data']['g-recaptcha-response'] ?? null);
                if (!$token) {
                    $post = $grav['uri']->post();
                    if (is_array($post)) {
                        if (isset($post['g-recaptcha-response'])) {
                            $token = $post['g-recaptcha-response'];
                        } elseif (isset($post['g_recaptcha_response'])) {
                            $token = $post['g_recaptcha_response'];
                        } elseif (isset($post['data']) && is_array($post['data'])) {
                            if (isset($post['data']['g-recaptcha-response'])) {
                                $token = $post['data']['g-recaptcha-response'];
                            } elseif (isset($post['data']['g_recaptcha_response'])) {
                                $token = $post['data']['g_recaptcha_response'];
                            }
                        }
                    }
                }

                if (!$token) {
                    $grav['log']->debug('reCAPTCHA validation failed: g-recaptcha-response missing for v2');
                    return [
                        'success' => false,
                        'error' => 'missing-input-response',
                        'details' => ['error' => 'missing-input-response', 'version' => 'v2']
                    ];
                }

                $recaptcha->setExpectedHostname($hostname);
            }

            // Log validation attempt
            $grav['log']->debug('reCAPTCHA validation attempt for version ' . $version);

            $validationResponseObject = $recaptcha->verify($token, $ip);
            $isValid = $validationResponseObject->isSuccess();

            if (!$isValid) {
                $errorCodes = $validationResponseObject->getErrorCodes();
                $grav['log']->debug('reCAPTCHA validation failed: ' . json_encode($errorCodes));

                return [
                    'success' => false,
                    'error' => 'validation-failed',
                    'details' => ['error-codes' => $errorCodes, 'version' => $version]
                ];
            }

            // For V3, check if score is available and log it (helpful for debugging/tuning)
            if ($version === '3' && method_exists($validationResponseObject, 'getScore')) {
                $score = $validationResponseObject->getScore();
                $grav['log']->debug('reCAPTCHA v3 validation successful with score: ' . $score);
            } else {
                $grav['log']->debug('reCAPTCHA validation successful');
            }

            return [
                'success' => true
            ];
        } catch (\Exception $e) {
            $grav['log']->error('reCAPTCHA validation error: ' . $e->getMessage());

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'details' => ['exception' => get_class($e)]
            ];
        }
    }

    /**
     * Normalize version values to the internal format we use elsewhere.
     */
    protected function normalizeVersion($version): string
    {
        if ($version === null || $version === '') {
            return '2-checkbox';
        }

        if ($version === 3 || $version === '3') {
            return '3';
        }

        if ($version === 2 || $version === '2') {
            return '2-checkbox';
        }

        return (string) $version;
    }

    /**
     * Infer the recaptcha version from the submitted payload when possible.
     */
    protected function detectVersionFromPayload(array $form): ?string
    {
        $formData = isset($form['data']) && is_array($form['data']) ? $form['data'] : [];

        $grav = Grav::instance();
        $config = $grav['config'];
        if ($config->get('plugins.form.debug')) {
            try {
                $grav['log']->debug('reCAPTCHA payload inspection', [
                    'top_keys' => array_keys($form),
                    'data_keys' => array_keys($formData),
                ]);
            } catch (\Throwable $e) {
                // Ignore logging issues, detection should continue.
            }
        }

        if (array_key_exists('token', $form) || array_key_exists('token', $formData)) {
            return '3';
        }

        if (array_key_exists('g-recaptcha-response', $form) || array_key_exists('g-recaptcha-response', $formData)) {
            return '2-checkbox';
        }

        if (array_key_exists('g_recaptcha_response', $form) || array_key_exists('g_recaptcha_response', $formData)) {
            // Support alternative key naming just in case
            return '2-checkbox';
        }

        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function getClientProperties(string $formId, array $field): array
    {
        $siteKey = $field['recaptcha_site_key'] ?? $this->config['site_key'] ?? null;
        $theme = $field['recaptcha_theme'] ?? $this->config['theme'] ?? 'light';
        $version = $this->normalizeVersion($field['recaptcha_version'] ?? $this->config['version'] ?? '2-checkbox');

        // Determine which version we're using
        $isV3 = $version === '3';
        $isInvisible = $version === '2-invisible';

        // Log the configuration to help with debugging
        $grav = Grav::instance();
        $grav['log']->debug("reCAPTCHA config for form {$formId}: version={$version}, siteKey=" .
                          (empty($siteKey) ? 'MISSING' : 'configured'));

        return [
            'provider' => 'recaptcha',
            'siteKey' => $siteKey,
            'theme' => $theme,
            'version' => $version,
            'isV3' => $isV3,
            'isInvisible' => $isInvisible,
            'containerId' => "g-recaptcha-{$formId}",
            'scriptUrl' => "https://www.google.com/recaptcha/api.js" . ($isV3 ? '?render=' . $siteKey : ''),
            'initFunctionName' => "initRecaptcha_{$formId}"
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getTemplateName(): string
    {
        // Different templates based on version
        $version = $this->normalizeVersion($this->config['version'] ?? '2-checkbox');

        $isV3 = $version === '3';
        $isInvisible = $version === '2-invisible';

        if ($isV3) {
            return 'forms/fields/recaptcha/recaptchav3.html.twig';
        } elseif ($isInvisible) {
            return 'forms/fields/recaptcha/recaptcha-invisible.html.twig';
        }

        return 'forms/fields/recaptcha/recaptcha.html.twig';
    }
}
