<?php
namespace Grav\Plugin\Form\Captcha;

use Grav\Plugin\Form\BasicCaptcha;
use Grav\Common\Grav;

/**
 * Enhanced basic image captcha provider implementation
 */
class BasicCaptchaProvider implements CaptchaProviderInterface
{
    /**
     * {@inheritdoc}
     */
    public function validate(array $form, array $params = []): array
    {
        $captcha = new BasicCaptcha();

        // Get form data from different possible structures
        $formData = $form['data'] ?? $form;

        // Try to find the captcha field value - handle both kebab and snake case
        $captcha_value = trim($formData['basic-captcha'] ?? $formData['basic_captcha'] ?? '');

        // Log debug information if debugging is enabled
        if (Grav::instance()['config']->get('plugins.form.basic_captcha.debug', false)) {
            Grav::instance()['log']->debug('Basic Captcha - Form Data: ' . json_encode($formData));
            Grav::instance()['log']->debug('Basic Captcha - Submitted Value: ' . $captcha_value);
            Grav::instance()['log']->debug('Basic Captcha - Expected Value: ' . $captcha->getSession());
        }

        if (!$captcha->validateCaptcha($captcha_value)) {
            return [
                'success' => false,
                'error' => 'invalid-captcha',
                'details' => ['error' => 'basic-captcha-not-valid']
            ];
        }

        return [
            'success' => true
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getClientProperties(string $formId, array $field): array
    {
        $config = Grav::instance()['config']->get('plugins.form.basic_captcha');
        $captchaType = $config['type'] ?? 'characters';

        // Generate unique identifiers for the captcha elements
        $containerId = "basic-captcha-{$formId}";
        $imageId = "basic-captcha-image-{$formId}";
        $reloadId = "basic-captcha-reload-{$formId}";

        // Include proper instructions based on captcha type
        $instructions = $this->getCaptchaInstructions($captchaType);

        return [
            'provider' => 'basic-captcha',
            'containerId' => $containerId,
            'imageId' => $imageId,
            'reloadId' => $reloadId,
            'imageUrl' => '/forms-basic-captcha-image.jpg',
            'instructions' => $instructions,
            'type' => $captchaType
        ];
    }

    /**
     * Get appropriate instructions for the captcha type
     */
    protected function getCaptchaInstructions($type): string
    {
        switch ($type) {
            case 'dotcount':
                return 'Count the colored dots shown in the image.';
            case 'position':
                return 'Identify the position of the symbol (top, bottom, left, right, center, etc).';
            case 'colorcount':
                return 'Count the objects of the specified color and shape.';
            case 'pathtracing':
                return 'Follow the path to find the number at the end.';
            case 'equation':
                return 'Solve the equation using the given symbol values.';
            case 'math':
                return 'Solve the math problem.';
            case 'characters':
            default:
                return 'Enter the text shown in the image.';
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getTemplateName(): string
    {
        return 'forms/fields/basic-captcha/basic-captcha.html.twig';
    }
}