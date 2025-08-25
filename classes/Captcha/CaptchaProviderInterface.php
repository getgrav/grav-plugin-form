<?php
namespace Grav\Plugin\Form\Captcha;

/**
 * Interface for captcha providers
 */
interface CaptchaProviderInterface
{
    /**
     * Validate a captcha response
     *
     * @param array $form Form data array
     * @param array $params Optional parameters
     * @return array Validation result with 'success' key and optional 'error' and 'details' keys
     */
    public function validate(array $form, array $params = []): array;

    /**
     * Get client-side properties for the captcha
     *
     * @param string $formId Form ID
     * @param array $field Field definition
     * @return array Client properties
     */
    public function getClientProperties(string $formId, array $field): array;

    /**
     * Get the template name for the captcha field
     *
     * @return string
     */
    public function getTemplateName(): string;
}