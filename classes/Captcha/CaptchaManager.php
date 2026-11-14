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
     * @param string|null $action The process action that asked for the check
     * @return bool True if validation succeeded
     */
    public static function validateCaptcha(Form $form, $params = null, ?string $action = null): bool
    {
        // Handle case where $params is a boolean (backward compatibility)
        if (!is_array($params)) {
            $params = [];
        }

        // --- 1. Find the captcha field in the form ---
        $found = self::findCaptchaField($form);
        if ($found === null) {
            // A `cap` action needs the Cap field to mint its token, so without one the
            // check could never pass: refuse the form rather than let everything
            // through. Other actions keep passing, as older forms rely on that.
            if ($action === 'cap') {
                Grav::instance()['log']->error("Form Captcha: form '{$form->getName()}' has a cap action but no Cap field");
                $errorMessage = Grav::instance()['language']->translate('PLUGIN_FORM.ERROR_VALIDATING_CAPTCHA');
                Grav::instance()->fireEvent('onFormValidationError', new Event([
                    'form' => $form,
                    'message' => $errorMessage,
                    'messages' => $form->messages ?? [],
                    'provider' => 'cap'
                ]));

                return false;
            }

            // No captcha field found or no provider specified
            return true;
        }
        [$fieldName, $captchaField, $providerName] = $found;

        $captchaFieldName = $captchaField['name'] ?? $fieldName;

        // --- 2. Get provider and validate ---
        $provider = CaptchaFactory::getProvider($providerName);
        if (!$provider) {
            $reason = $providerName === 'cap' && PHP_VERSION_ID < 80100 ? ' (Cap needs PHP 8.1 or newer)' : '';
            Grav::instance()['log']->error("Form Captcha: Unknown provider '{$providerName}' requested{$reason}");
            return false;
        }

        // A per-form reCAPTCHA version override comes from the blueprint field
        // definition, on the server -- never from the submitted payload. Hand it to the
        // provider as a validation parameter so it does not have to sniff the request to
        // find it (GHSA-89j6-8h38-2cc3).
        if (!isset($params['recaptcha_version']) && is_array($captchaField) && isset($captchaField['recaptcha_version'])) {
            $params = (array) $params;
            $params['recaptcha_version'] = $captchaField['recaptcha_version'];
        }

        // The form being submitted, so a provider can tie its token to this form (Cap does).
        $params['form_name'] = $form->getName();
        // The captcha field's name, so a provider that reads its answer from the
        // posted data (basic-captcha does) finds it whatever the field is called.
        $params['field_name'] = $fieldName;

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
            $result = $provider->validate($form->value(), $params);

            if (!$result['success']) {
                $logDetails = $result['details'] ?? [];
                $errorMessage = self::getErrorMessage($captchaField, $result['error'] ?? 'validation-failed', $providerName);

                // Fire validation error event
                Grav::instance()->fireEvent('onFormValidationError', new Event([
                    'form' => $form,
                    'message' => $errorMessage,
                    'messages' => array_merge($form->messages ?? [], [$captchaFieldName => [$errorMessage]]),
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
                'messages' => array_merge($form->messages ?? [], [$captchaFieldName => [$errorMessage]]),
                'provider' => $providerName,
                'exception' => $e
            ]));

            return false;
        }
    }

    /**
     * Process actions that validate a captcha. They all end up in validateCaptcha().
     */
    public const PROCESS_ACTIONS = ['captcha', 'cap', 'turnstile', 'basic-captcha'];

    /**
     * Find the form's captcha field, including one nested in columns, fieldsets, tabs
     * or any other field that holds `fields`.
     *
     * @param Form $form
     * @return array|null [field name, field definition, provider name], or null when the form has no captcha
     */
    public static function findCaptchaField(Form $form): ?array
    {
        $fields = $form->getBlueprint()->get('form/fields');

        return is_array($fields) ? self::searchFields($fields) : null;
    }

    /**
     * @param array $fields
     * @return array|null
     */
    protected static function searchFields(array $fields): ?array
    {
        foreach ($fields as $fieldName => $fieldDef) {
            if (!is_array($fieldDef)) {
                continue;
            }
            $fieldType = $fieldDef['type'] ?? null;

            // Modern captcha type with provider
            if ($fieldType === 'captcha') {
                return [$fieldName, $fieldDef, $fieldDef['provider'] ?? 'recaptcha'];
            }

            // Legacy type-based providers (like basic-captcha and turnstile), kept for
            // backward compatibility. A `cap` field still counts when its provider isn't
            // registered (PHP < 8.1), so the form is refused instead of skipping the captcha.
            if ($fieldType && (CaptchaFactory::hasProvider($fieldType) || $fieldType === 'cap')) {
                return [$fieldName, $fieldDef, $fieldType];
            }

            if (isset($fieldDef['fields']) && is_array($fieldDef['fields'])) {
                $found = self::searchFields($fieldDef['fields']);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    /**
     * Whether a form's `process` list already has a captcha action, including an
     * explicit `captcha: false`, which turns the check off on purpose.
     *
     * @param array $process
     * @return bool
     */
    public static function hasProcessAction(array $process): bool
    {
        foreach ($process as $action => $data) {
            if (is_numeric($action)) {
                if (is_string($data)) {
                    $action = $data;
                } elseif (is_array($data)) {
                    $action = key($data);
                } else {
                    continue;
                }
            }
            if (in_array($action, self::PROCESS_ACTIONS, true)) {
                return true;
            }
        }

        return false;
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

        // Sent before the form could have been read: say so, whatever the field's own message is,
        // since the fix is simply to send it again.
        if ($errorCode === 'submitted-too-fast') {
            return $grav['language']->translate('PLUGIN_FORM.ERROR_CAPTCHA_TOO_FAST');
        }

        // First check for specific message in field definition. `captcha_not_validated`
        // is the canonical key; `recaptcha_not_validated` is the legacy key kept for
        // backward compatibility with older form definitions.
        if (isset($field['captcha_not_validated'])) {
            return $field['captcha_not_validated'];
        }
        if (isset($field['recaptcha_not_validated'])) {
            return $field['recaptcha_not_validated'];
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
