<?php
namespace Grav\Plugin\Form\Captcha;

use Grav\Plugin\Form\BasicCaptcha;

/**
 * Basic image captcha provider implementation
 */
class BasicCaptchaProvider implements CaptchaProviderInterface
{
    /**
     * {@inheritdoc}
     */
    public function validate(array $form, array $params = []): array
    {
        $captcha = new BasicCaptcha();
        $captcha_value = trim($form['basic-captcha'] ?? '');

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
        return [
            'provider' => 'basic-captcha',
            'containerId' => "basic-captcha-{$formId}",
            'imageUrl' => '/forms-basic-captcha-image.jpg'
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