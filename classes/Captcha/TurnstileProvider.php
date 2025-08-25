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
        $grav = Grav::instance();
        $uri = $grav['uri'];
        $ip = Uri::ip();

        $grav['log']->debug('Turnstile validation - entire form data: ' . json_encode(array_keys($form)));

        try {
            $secretKey = $params['turnstile_secret'] ??
                       $this->config['secret_key'] ?? null;

            if (!$secretKey) {
                $grav['log']->error("Turnstile secret key not configured.");
                throw new \RuntimeException("Turnstile secret key not configured.");
            }

            // First check $_POST directly, then fallback to form data
            $token = $_POST['cf-turnstile-response'] ?? null;
            if (!$token) {
                $token = $form['cf-turnstile-response'] ?? null;
            }

            // Log raw POST data for debugging
            $grav['log']->debug('Turnstile validation - raw POST data keys: ' . json_encode(array_keys($_POST)));
            $grav['log']->debug('Turnstile validation - token present: ' . ($token ? 'YES' : 'NO'));

            if ($token) {
                $grav['log']->debug('Turnstile token length: ' . strlen($token));
            }

            if (!$token) {
                $grav['log']->warning('Turnstile validation failed: missing token response');
                return [
                    'success' => false,
                    'error' => 'missing-input-response',
                    'details' => ['error' => 'missing-input-response']
                ];
            }

            $client = \Grav\Common\HTTP\Client::getClient();
            $grav['log']->debug('Turnstile validation - calling API with token');

            $response = $client->request('POST', 'https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                'body' => [
                    'secret' => $secretKey,
                    'response' => $token,
                    'remoteip' => $ip
                ]
            ]);

            $statusCode = $response->getStatusCode();
            $grav['log']->debug('Turnstile API response status: ' . $statusCode);

            $content = $response->toArray();
            $grav['log']->debug('Turnstile API response: ' . json_encode($content));

            if (!isset($content['success'])) {
                $grav['log']->error("Invalid response from Turnstile verification (missing 'success' key).");
                throw new \RuntimeException("Invalid response from Turnstile verification (missing 'success' key).");
            }

            if (!$content['success']) {
                $grav['log']->warning('Turnstile validation failed: ' . json_encode($content));
                return [
                    'success' => false,
                    'error' => 'validation-failed',
                    'details' => ['error-codes' => $content['error-codes'] ?? ['validation-failed']]
                ];
            }

            $grav['log']->debug('Turnstile validation successful');
            return [
                'success' => true
            ];
        } catch (\Exception $e) {
            $grav['log']->error("Turnstile validation error: " . $e->getMessage());
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