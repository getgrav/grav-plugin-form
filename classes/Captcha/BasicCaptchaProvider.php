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

            // Get the captcha type from session (stored during generation)
            $captchaType = $session->basic_captcha_type ?? null;

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

            // Compare the values based on the type stored in session
            // If type is not in session, try to infer from global/field config
            if (!$captchaType) {
                $captchaType = $this->config['captcha_type'] ?? $this->config['type'] ?? 'characters';
            }

            if ($captchaType === 'characters') {
                $isValid = strtolower((string)$userValue) === strtolower((string)$expectedValue);
            } else {
                // For math, dotcount, position - ensure both are treated as integers or exact match
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

            // Clear the session values to prevent reuse
            $session->basic_captcha_value = null;
            $session->basic_captcha_type = null;

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
        $grav = Grav::instance();
        $session = $grav['session'];

        // Merge field-level configuration with global defaults
        $fieldConfig = array_replace_recursive($this->config, $field);

        // Remove non-config keys from field array
        unset($fieldConfig['type'], $fieldConfig['label'], $fieldConfig['placeholder'],
              $fieldConfig['validate'], $fieldConfig['name'], $fieldConfig['classes']);

        // Generate unique field ID for this form/field combination
        $fieldId = md5($formId . '_basic_captcha_' . ($field['name'] ?? 'default'));

        // Store field configuration in session for image generation
        $session->{"basic_captcha_config_{$fieldId}"} = $fieldConfig;

        $captchaType = $fieldConfig['type'] ?? 'math';

        return [
            'provider' => 'basic-captcha',
            'type' => $captchaType,
            'imageUrl' => "/forms-basic-captcha-image.jpg?field={$fieldId}",
            'refreshable' => true,
            'containerId' => "basic-captcha-{$formId}",
            'fieldId' => $fieldId
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