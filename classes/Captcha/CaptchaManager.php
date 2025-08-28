<?php
namespace Grav\Plugin\Form\Captcha;

use Grav\Common\Grav;
use Grav\Plugin\Form\Form;
use RocketTheme\Toolbox\Event\Event;

/**
 * Central manager for captcha processing
 */
class CaptchaManager
{
    /**
     * Initialize the captcha manager
     *
     * @return void
     */
    public static function initialize(): void
    {
        // Register all default captcha providers
        CaptchaFactory::registerDefaultProviders();

        // Allow plugins to register custom captcha providers
        Grav::instance()->fireEvent('onFormRegisterCaptchaProviders');
    }

    /**
     * Process a captcha validation
     *
     * @param Form $form The form to validate
     * @param array|null $params Optional parameters
     * @return bool True if validation succeeded
     */
    public static function validateCaptcha(Form $form, $params = null): bool
    {
        // Handle case where $params is a boolean (backward compatibility)
        if (!is_array($params)) {
            $params = [];
        }

        // --- 1. Find the captcha field in the form ---
        $captchaField = null;
        $providerName = null;

        $formFields = $form->value()->blueprints()->get('form/fields');
        foreach ($formFields as $fieldName => $fieldDef) {
            $fieldType = $fieldDef['type'] ?? null;

            // Check for modern captcha type with provider
            if ($fieldType === 'captcha') {
                $captchaField = $fieldDef;
                $providerName = $fieldDef['provider'] ?? 'recaptcha';
                break;
            }

            // Check for legacy type-based providers (like basic-captcha and turnstile)
            // This is for backward compatibility
            elseif ($fieldType && CaptchaFactory::hasProvider($fieldType)) {
                $captchaField = $fieldDef;
                $providerName = $fieldType;
                break;
            }
        }

        if (!$captchaField || !$providerName) {
            // No captcha field found or no provider specified
            return true;
        }

        // --- 2. Get provider and validate ---
        $provider = CaptchaFactory::getProvider($providerName);
        if (!$provider) {
            Grav::instance()['log']->error("Form Captcha: Unknown provider '{$providerName}' requested");
            return false;
        }

        // Allow plugins to modify the validation parameters
        $validationEvent = new Event([
            'form' => $form,
            'field' => $captchaField,
            'provider' => $providerName,
            'params' => $params
        ]);
        Grav::instance()->fireEvent('onBeforeCaptchaValidation', $validationEvent);
        $params = $validationEvent['params'];

        // Validate using the provider
        try {
            $result = $provider->validate($form->value()->toArray(), $params);

            if (!$result['success']) {
                $logDetails = $result['details'] ?? [];
                $errorMessage = self::getErrorMessage($captchaField, $result['error'] ?? 'validation-failed', $providerName);

                // Fire validation error event
                Grav::instance()->fireEvent('onFormValidationError', new Event([
                    'form' => $form,
                    'message' => $errorMessage,
                    'provider' => $providerName
                ]));

                // Log the failure
                $uri = Grav::instance()['uri'];
                Grav::instance()['log']->warning(
                    "Form Captcha ({$providerName}) validation failed: [{$uri->route()}] Details: " .
                    json_encode($logDetails)
                );

                return false;
            }

            // Log success
            Grav::instance()['log']->info("Form Captcha ({$providerName}) validation successful for form: " . $form->name);

            // Fire success event
            Grav::instance()->fireEvent('onCaptchaValidationSuccess', new Event([
                'form' => $form,
                'provider' => $providerName
            ]));

            return true;
        } catch (\Exception $e) {
            // Handle other errors
            Grav::instance()['log']->error("Form Captcha ({$providerName}) validation error: " . $e->getMessage());

            $errorMessage = Grav::instance()['language']->translate('PLUGIN_FORM.ERROR_VALIDATING_CAPTCHA');
            Grav::instance()->fireEvent('onFormValidationError', new Event([
                'form' => $form,
                'message' => $errorMessage,
                'provider' => $providerName,
                'exception' => $e
            ]));

            return false;
        }
    }

    /**
     * Get appropriate error message based on error code and field definition
     *
     * @param array $field Field definition
     * @param string $errorCode Error code
     * @param string $provider Provider name
     * @return string
     */
    protected static function getErrorMessage(array $field, string $errorCode, string $provider): string
    {
        $grav = Grav::instance();

        // First check for specific message in field definition
        if (isset($field['captcha_not_validated'])) {
            return $field['captcha_not_validated'];
        }

        // Then check for specific error code message
        if ($errorCode === 'missing-input-response') {
            return $grav['language']->translate('PLUGIN_FORM.ERROR_CAPTCHA_NOT_COMPLETED');
        }

        // Allow providers to supply custom error messages via event
        $messageEvent = new Event([
            'provider' => $provider,
            'errorCode' => $errorCode,
            'field' => $field,
            'message' => null
        ]);
        $grav->fireEvent('onCaptchaErrorMessage', $messageEvent);

        if ($messageEvent['message']) {
            return $messageEvent['message'];
        }

        // Finally fall back to generic message
        return $grav['language']->translate('PLUGIN_FORM.ERROR_VALIDATING_CAPTCHA');
    }

    /**
     * Get client-side initialization data for a captcha field
     *
     * @param string $formId Form ID
     * @param array $field Field definition
     * @return array Client properties
     */
    public static function getClientProperties(string $formId, array $field): array
    {
        $providerName = $field['provider'] ?? null;

        // Handle legacy field types as providers
        if (!$providerName && isset($field['type'])) {
            $fieldType = $field['type'];
            if (CaptchaFactory::hasProvider($fieldType)) {
                $providerName = $fieldType;
            }
        }

        if (!$providerName) {
            // Default to recaptcha for backward compatibility
            $providerName = 'recaptcha';
        }

        $provider = CaptchaFactory::getProvider($providerName);

        if (!$provider) {
            return [
                'provider' => $providerName,
                'error' => "Unknown captcha provider: {$providerName}"
            ];
        }

        return $provider->getClientProperties($formId, $field);
    }

    /**
     * Get template name for a captcha field
     *
     * @param array $field Field definition
     * @return string Template name
     */
    public static function getTemplateName(array $field): string
    {
        $providerName = $field['provider'] ?? null;

        // Handle legacy field types as providers
        if (!$providerName && isset($field['type'])) {
            $fieldType = $field['type'];
            if (CaptchaFactory::hasProvider($fieldType)) {
                $providerName = $fieldType;
            }
        }

        if (!$providerName) {
            // Default to recaptcha for backward compatibility
            $providerName = 'recaptcha';
        }

        $provider = CaptchaFactory::getProvider($providerName);

        if (!$provider) {
            return 'forms/fields/captcha/default.html.twig';
        }

        return $provider->getTemplateName();
    }
}