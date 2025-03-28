<?php
namespace Grav\Plugin\Form\Captcha;

use Grav\Common\Grav;
use Grav\Common\Uri;
use ReCaptcha\ReCaptcha;
use ReCaptcha\RequestMethod\CurlPost;

/**
 * Google reCAPTCHA provider
 */
class ReCaptchaProvider implements CaptchaProviderInterface
{
    /** @var array */
    protected $config;

    /** @var int */
    protected $version;

    public function __construct()
    {
        $this->config = Grav::instance()['config']->get('plugins.form.recaptcha', []);
        $this->version = $this->config['version'] ?? 2;
    }

    /**
     * {@inheritdoc}
     */
    public function validate(array $form, array $params = []): array
    {
        $uri = Uri::getInstance();
        $ip = Uri::ip();
        $hostname = $uri->host();

        try {
            $secretKey = $params['recaptcha_secret'] ?? $params['recatpcha_secret'] ??
                      $this->config['secret_key'] ?? null;

            if (!$secretKey) {
                throw new \RuntimeException("reCAPTCHA secret key not configured.");
            }

            $requestMethod = extension_loaded('curl') ? new CurlPost() : null;
            $recaptcha = new ReCaptcha($secretKey, $requestMethod);

            if ($this->version == 3) {
                $token = $form['token'] ?? null;
                $action = $form['action'] ?? null;

                if (!$token) {
                    throw new \RuntimeException("reCAPTCHA v3 response token not found.");
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
        $version = $field['recaptcha_version'] ?? $this->version;
        $siteKey = $field['recaptcha_site_key'] ?? $this->config['site_key'] ?? null;

        return [
            'provider' => 'recaptcha',
            'version' => $version,
            'siteKey' => $siteKey,
            'containerId' => $version == 2 ? "g-recaptcha-{$formId}" : null,
            'scriptUrl' => $version == 3
                ? "https://www.google.com/recaptcha/api.js?render={$siteKey}"
                : "https://www.google.com/recaptcha/api.js",
            'initFunctionName' => "initRecaptcha_{$formId}"
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getTemplateName(): string
    {
        // This now points to a single template file
        return "forms/fields/recaptcha/recaptcha.html.twig";
    }
}