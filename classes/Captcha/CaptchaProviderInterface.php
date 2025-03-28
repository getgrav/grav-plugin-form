<?php
namespace Grav\Plugin\Form\Captcha;

/**
 * Interface for all captcha providers
 */
interface CaptchaProviderInterface
{
    /**
     * Validate the captcha response
     *
     * @param array $form The form data
     * @param array $params Optional parameters from form definition
     * @return array Result with keys: success (bool), error (string|null), details (array|null)
     */
    public function validate(array $form, array $params = []): array;

    /**
     * Get client-side initialization properties
     *
     * @param string $formId The HTML form ID
     * @param array $field The field definition
     * @return array Data needed for client initialization
     */
    public function getClientProperties(string $formId, array $field): array;

    /**
     * Get the form field template to use
     *
     * @return string Template name
     */
    public function getTemplateName(): string;
}