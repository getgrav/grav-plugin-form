<?php
namespace Grav\Plugin\Form\Captcha;

use Grav\Common\Grav;
use Grav\Common\Uri;

/**
 * Google reCAPTCHA provider implementation
 */
class RecaptchaProvider implements CaptchaProviderInterface
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
            $version = $this->config['version'] ?? 2;

            if (!$secretKey) {
                throw new \RuntimeException("reCAPTCHA secret key not configured.");
            }

            $requestMethod = extension_loaded('curl') ? new \ReCaptcha\RequestMethod\CurlPost() : null;
            $recaptcha = new \ReCaptcha\ReCaptcha($secretKey, $requestMethod);

            if ($version == 3) {
                $token = $form['token'] ?? null;
                $action = $form['action'] ?? null;

                if (!$token) {
                    return [
                        'success' => false,
                        'error' => 'missing-input-response',
                        'details' => ['error' => 'missing-input-response']
                    ];
                }

                $recaptcha->setExpectedHostname($hostname)
                          ->setExpectedAction($action)
                          ->setScoreThreshold($this->config['score_threshold'] ?? 0.5);
            } else {
                $token = $form['g-recaptcha-response'] ?? null;

                if (!$token) {
                    return [
                        'success' => false,
                        'error' => 'missing-input-response',
                        'details' => ['error' => 'missing-input-response']
                    ];
                }

                $recaptcha->setExpectedHostname($hostname);
            }

            $validationResponseObject = $recaptcha->verify($token, $ip);
            $isValid = $validationResponseObject->isSuccess();

            if (!$isValid) {
                return [
                    'success' => false,
                    'error' => 'validation-failed',
                    'details' => ['error-codes' => $validationResponseObject->getErrorCodes()]
                ];
            }

            return [
                'success' => true
            ];
        } catch (\Exception $e) {
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
        $version = $this->config['version'] ?? '2-checkbox';

        // Determine which version we're using
        $isV3 = strpos($version, '3') === 0;
        $isInvisible = strpos($version, '2-invisible') === 0;

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
        $isV3 = strpos($version, '3') === 0;

        if ($isV3) {
            return 'forms/fields/recaptcha/recaptchav3.html.twig';
        }

        return 'forms/fields/recaptcha/recaptcha.html.twig';
    }
}