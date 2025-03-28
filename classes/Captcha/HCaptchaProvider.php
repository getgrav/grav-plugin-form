<?php
namespace Grav\Plugin\Form\Captcha;

use Grav\Common\Grav;
use Grav\Common\Uri;
use Grav\Common\HTTP\Client;

/**
 * hCaptcha provider implementation
 */
class HCaptchaProvider implements CaptchaProviderInterface
{
    /** @var array */
    protected $config;

    public function __construct()
    {
        $this->config = Grav::instance()['config']->get('plugins.form.hcaptcha', []);
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
            $secretKey = $params['hcaptcha_secret'] ??
                       $this->config['secret_key'] ?? null;

            if (!$secretKey) {
                throw new \RuntimeException("hCaptcha secret key not configured.");
            }

            $token = $form['h-captcha-response'] ?? null;

            if (!$token) {
                return [
                    'success' => false,
                    'error' => 'missing-input-response',
                    'details' => ['error' => 'missing-input-response']
                ];
            }

            $postData = [
                'secret' => $secretKey,
                'response' => $token,
                'hostname' => $hostname,
            ];

            $validationUrl = 'https://hcaptcha.com/siteverify';
            $httpClient = Client::getClient();

            $response = $httpClient->request('POST', $validationUrl, [
                'body' => $postData,
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode < 200 || $statusCode >= 300) {
                throw new \RuntimeException("hCaptcha verification request failed with status code: ".$statusCode);
            }

            $responseBody = $response->getContent();
            $validationResponseData = json_decode($responseBody, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \RuntimeException("Invalid JSON received from hCaptcha: ".json_last_error_msg());
            }

            if (!isset($validationResponseData['success'])) {
                throw new \RuntimeException("Invalid response format from hCaptcha verification (missing 'success' key).");
            }

            $isValid = $validationResponseData['success'];

            if (!$isValid) {
                return [
                    'success' => false,
                    'error' => 'validation-failed',
                    'details' => ['error-codes' => $validationResponseData['error-codes'] ?? ['validation-failed']]
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
        $siteKey = $field['hcaptcha_site_key'] ?? $this->config['site_key'] ?? null;
        $theme = $field['hcaptcha_theme'] ?? $this->config['theme'] ?? 'light';
        $size = $field['hcaptcha_size'] ?? $this->config['size'] ?? 'normal';

        return [
            'provider' => 'hcaptcha',
            'siteKey' => $siteKey,
            'theme' => $theme,
            'size' => $size,
            'containerId' => "h-captcha-{$formId}",
            'scriptUrl' => "https://js.hcaptcha.com/1/api.js",
            'initFunctionName' => "initHCaptcha_{$formId}"
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getTemplateName(): string
    {
        return "forms/fields/hcaptcha/hcaptcha.html.twig";
    }
}