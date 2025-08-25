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
            $version = $this->config['version'] ?? '2-checkbox';

            if (!$secretKey) {
                throw new \RuntimeException("reCAPTCHA secret key not configured.");
            }

            $requestMethod = extension_loaded('curl') ? new \ReCaptcha\RequestMethod\CurlPost() : null;
            $recaptcha = new \ReCaptcha\ReCaptcha($secretKey, $requestMethod);

            // Handle V3
            if ($version == 3 || $version == '3') {
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
                $token = $form['g-recaptcha-response'] ?? null;

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
            if (($version == 3 || $version == '3') && method_exists($validationResponseObject, 'getScore')) {
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
     * {@inheritdoc}
     */
    public function getClientProperties(string $formId, array $field): array
    {
        $siteKey = $field['recaptcha_site_key'] ?? $this->config['site_key'] ?? null;
        $theme = $field['recaptcha_theme'] ?? $this->config['theme'] ?? 'light';
        $version = $field['recaptcha_version'] ?? $this->config['version'] ?? '2-checkbox';

        // Normalize version format
        if ($version === 2 || $version === '2') {
            $version = '2-checkbox';
        } elseif ($version === 3 || $version === '3') {
            $version = '3';
        }

        // Determine which version we're using
        $isV3 = $version === '3' || $version === 3;
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
        $version = $this->config['version'] ?? '2-checkbox';

        // Normalize version format
        if ($version === 2 || $version === '2') {
            $version = '2-checkbox';
        } elseif ($version === 3 || $version === '3') {
            $version = '3';
        }

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