<?php
namespace Grav\Plugin\Form\Captcha;

use Grav\Common\Grav;

/**
 * Basic Captcha provider implementation
 */
class BasicCaptchaProvider implements CaptchaProviderInterface
{
    /** @var array */
    protected $config;

    public function __construct()
    {
        $this->config = Grav::instance()['config']->get('plugins.form.basic_captcha', []);
    }

    /**
     * {@inheritdoc}
     */
    public function validate(array $form, array $params = []): array
    {
        $grav = Grav::instance();
        $session = $grav['session'];

        try {
            // Get the expected answer from session
            // Make sure to use the same session key that the image generation code uses
            $expectedValue = $session->basic_captcha_value ?? null; // Changed from basic_captcha to basic_captcha_value

            // Get the user's answer
            $userValue = $form['basic-captcha'] ?? null;

            if (!$expectedValue) {
                return [
                    'success' => false,
                    'error' => 'missing-session-data',
                    'details' => ['error' => 'No captcha value found in session']
                ];
            }

            if (!$userValue) {
                return [
                    'success' => false,
                    'error' => 'missing-input-response',
                    'details' => ['error' => 'User did not enter a captcha value']
                ];
            }

            // Compare the values (case-insensitive string comparison for character captchas)
            $captchaType = $this->config['type'] ?? 'math';

            if ($captchaType === 'characters') {
                $isValid = strtolower((string)$userValue) === strtolower((string)$expectedValue);
            } else {
                // For math, ensure both are treated as integers
                $isValid = (int)$userValue === (int)$expectedValue;
            }

            if (!$isValid) {
                return [
                    'success' => false,
                    'error' => 'validation-failed',
                    'details' => [
                        'expected' => $expectedValue,
                        'received' => $userValue
                    ]
                ];
            }

            // Clear the session value to prevent reuse
            $session->basic_captcha_value = null;

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
        $captchaType = $field['basic_captcha_type'] ?? $this->config['type'] ?? 'math';

        return [
            'provider' => 'basic-captcha',
            'type' => $captchaType,
            'imageUrl' => '/forms-basic-captcha-image.jpg',
            'refreshable' => true,
            'containerId' => "basic-captcha-{$formId}"
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getTemplateName(): string
    {
        return 'forms/fields/basic-captcha/basic-captcha.html.twig';
    }
}