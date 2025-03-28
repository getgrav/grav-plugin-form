<?php
namespace Grav\Plugin\Form\Captcha;

use Grav\Common\Grav;
use Grav\Common\Uri;
use Grav\Common\HTTP\Client;

/**
 * Cloudflare Turnstile provider implementation
 */
class TurnstileProvider implements CaptchaProviderInterface
{
    /** @var array */
    protected $config;

    public function __construct()
    {
        $this->config = Grav::instance()['config']->get('plugins.form.turnstile', []);
    }

    /**
     * {@inheritdoc}
     */
    public function validate(array $form, array $params = []): array
    {
        $uri = Uri::getInstance();
        $ip = Uri::ip();

        try {
            $secretKey = $params['turnstile_secret'] ??
                       $this->config['secret_key'] ?? null;

            if (!$secretKey) {
                throw new \RuntimeException("Turnstile secret key not configured.");
            }

            $token = $form['cf-turnstile-response'] ?? null;

            if (!$token) {
                return [
                    'success' => false,
                    'error' => 'missing-input-response',
                    'details' => ['error' => 'missing-input-response']
                ];
            }

            $client = Client::getClient();
            $response = $client->request('POST', 'https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                'body' => [
                    'secret' => $secretKey,
                    'response' => $token,
                    'remoteip' => $ip
                ]
            ]);

            $content = $response->toArray();

            if (!isset($content['success'])) {
                throw new \RuntimeException("Invalid response from Turnstile verification (missing 'success' key).");
            }

            if (!$content['success']) {
                return [
                    'success' => false,
                    'error' => 'validation-failed',
                    'details' => ['error-codes' => $content['error-codes'] ?? ['validation-failed']]
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
        $siteKey = $field['turnstile_site_key'] ?? $this->config['site_key'] ?? null;
        $theme = $field['turnstile_theme'] ?? $this->config['theme'] ?? 'auto';

        return [
            'provider' => 'turnstile',
            'siteKey' => $siteKey,
            'theme' => $theme,
            'containerId' => "cf-turnstile-{$formId}",
            'scriptUrl' => "https://challenges.cloudflare.com/turnstile/v0/api.js",
            'initFunctionName' => "initTurnstile_{$formId}"
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getTemplateName(): string
    {
        return 'forms/fields/turnstile/turnstile.html.twig';
    }
}