<?php
namespace Grav\Plugin\Form\Captcha;

use Grav\Common\Grav;
use Grav\Common\Uri;
use Grav\Plugin\Form\Form;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

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
        Grav::instance()->fireEvent('onFormRegisterCaptchaProviders', new \RocketTheme\Toolbox\Event\Event());
    }

    /**
     * Process a captcha validation
     *
     * @param Form $form The form to validate
     * @param array $params Optional parameters
     * @return bool True if validation succeeded
     */
    public static function validateCaptcha(Form $form, mixed $params = []): bool
    {
        // Handle case where $params is a boolean (backward compatibility)
        if (!is_array($params)) {
            $params = [];
        }

        // --- 1. Find the captcha field in the form ---
        $captchaField = null;
        $providerName = 'recaptcha'; // Default provider

        $formFields = $form->value()->blueprints()->get('form/fields');
        foreach ($formFields as $fieldDef) {
            if (($fieldDef['type'] ?? null) === 'captcha') {
                $captchaField = $fieldDef;
                $providerName = $fieldDef['provider'] ?? 'recaptcha';
                break;
            }
        }

        if (!$captchaField) {
            // No captcha field found
            return true;
        }

        // --- 2. Select validation method based on provider ---
        // For backward compatibility, we should maintain built-in validation for common types
        if ($providerName === 'recaptcha') {
            return self::validateRecaptcha($form, $captchaField, $params);
        } elseif ($providerName === 'hcaptcha') {
            return self::validateHCaptcha($form, $captchaField, $params);
        } elseif ($providerName === 'turnstile') {
            return self::validateTurnstile($form, $captchaField, $params);
        } else {
            // Use provider factory for custom types
            $provider = CaptchaFactory::getProvider($providerName);
            if (!$provider) {
                Grav::instance()['log']->error("Form Captcha: Unknown provider '{$providerName}' requested");
                return false;
            }

            // Validate using the provider
            try {
                $result = $provider->validate($form->value()->toArray(), $params);

                if (!$result['success']) {
                    $logDetails = $result['details'] ?? [];
                    $errorMessage = self::getErrorMessage($captchaField, $result['error'] ?? 'validation-failed', $providerName);

                    // Fire validation error event
                    Grav::instance()->fireEvent('onFormValidationError', new \RocketTheme\Toolbox\Event\Event([
                        'form' => $form,
                        'message' => $errorMessage
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
                return true;
            } catch (\Exception $e) {
                // Handle other errors
                Grav::instance()['log']->error("Form Captcha ({$providerName}) validation error: " . $e->getMessage());

                $errorMessage = Grav::instance()['language']->translate('PLUGIN_FORM.ERROR_VALIDATING_CAPTCHA');
                Grav::instance()->fireEvent('onFormValidationError', new \RocketTheme\Toolbox\Event\Event([
                    'form' => $form,
                    'message' => $errorMessage
                ]));

                return false;
            }
        }
    }

    /**
     * Validate a reCAPTCHA response
     *
     * @param Form $form The form
     * @param array $field The captcha field definition
     * @param array $params Optional parameters
     * @return bool True if validation succeeded
     */
    protected static function validateRecaptcha(Form $form, array $field, array $params): bool
    {
        $grav = Grav::instance();
        $uri = $grav['uri'];
        $ip = Uri::ip();
        $hostname = $uri->host();

        try {
            $recaptchaConfig = $grav['config']->get('plugins.form.recaptcha', []);
            $secretKey = $params['recaptcha_secret'] ?? $params['recatpcha_secret'] ??
                       $field['recaptcha_secret'] ?? $recaptchaConfig['secret_key'] ?? null;
            $version = $recaptchaConfig['version'] ?? 2;

            if (!$secretKey) {
                throw new \RuntimeException("reCAPTCHA secret key not configured.");
            }

            $requestMethod = extension_loaded('curl') ? new \ReCaptcha\RequestMethod\CurlPost() : null;
            $recaptcha = new \ReCaptcha\ReCaptcha($secretKey, $requestMethod);

            if ($version == 3) {
                $token = $form->value('token');
                $action = $form->value('action');

                if (!$token) {
                    throw new \RuntimeException("reCAPTCHA v3 response token not found.");
                }

                $recaptcha->setExpectedHostname($hostname)
                          ->setExpectedAction($action)
                          ->setScoreThreshold($recaptchaConfig['score_threshold'] ?? 0.5);
            } else {
                $token = $form->value('g-recaptcha-response', true);

                if (!$token) {
                    throw new \RuntimeException("reCAPTCHA v2 response token not found.");
                }

                $recaptcha->setExpectedHostname($hostname);
            }

            $validationResponseObject = $recaptcha->verify($token, $ip);
            $isValid = $validationResponseObject->isSuccess();

            if (!$isValid) {
                $logDetails = ['error-codes' => $validationResponseObject->getErrorCodes()];

                $message = $field['captcha_not_validated'] ??
                          $grav['language']->translate('PLUGIN_FORM.ERROR_VALIDATING_CAPTCHA');

                $grav->fireEvent('onFormValidationError', new \RocketTheme\Toolbox\Event\Event([
                    'form' => $form,
                    'message' => $message
                ]));

                $grav['log']->warning("Form Captcha (recaptcha) validation failed: [{$uri->route()}] Details: " . json_encode($logDetails));
                return false;
            }

            return true;
        } catch (\Exception $e) {
            $grav['log']->error("Form Captcha (recaptcha) error: " . $e->getMessage());

            $message = $field['captcha_not_validated'] ??
                      $grav['language']->translate('PLUGIN_FORM.ERROR_VALIDATING_CAPTCHA');

            $grav->fireEvent('onFormValidationError', new \RocketTheme\Toolbox\Event\Event([
                'form' => $form,
                'message' => $message
            ]));

            return false;
        }
    }

    /**
     * Validate an hCaptcha response
     *
     * @param Form $form The form
     * @param array $field The captcha field definition
     * @param array $params Optional parameters
     * @return bool True if validation succeeded
     */
    protected static function validateHCaptcha(Form $form, array $field, array $params): bool
    {
        $grav = Grav::instance();
        $uri = $grav['uri'];
        $ip = Uri::ip();
        $hostname = $uri->host();

        try {
            $hcaptchaConfig = $grav['config']->get('plugins.form.hcaptcha', []);
            $secretKey = $params['hcaptcha_secret'] ??
                       $field['hcaptcha_secret'] ??
                       $hcaptchaConfig['secret_key'] ?? null;

            if (!$secretKey) {
                throw new \RuntimeException("hCaptcha secret key not configured.");
            }

            $token = $form->value('h-captcha-response', true);

            if (!$token) {
                $message = $field['captcha_not_validated'] ??
                          $grav['language']->translate('PLUGIN_FORM.ERROR_VALIDATING_HCAPTCHA');

                $grav->fireEvent('onFormValidationError', new \RocketTheme\Toolbox\Event\Event([
                    'form' => $form,
                    'message' => $message
                ]));

                $grav['log']->warning("Form Captcha (hcaptcha) validation failed: [{$uri->route()}] Details: missing-input-response");
                return false;
            }

            $postData = [
                'secret' => $secretKey,
                'response' => $token,
                'hostname' => $hostname,
            ];

            $validationUrl = 'https://hcaptcha.com/siteverify';
            $httpClient = \Grav\Common\HTTP\Client::getClient();

            $response = $httpClient->request('POST', $validationUrl, [
                'body' => $postData,
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode < 200 || $statusCode >= 300) {
                throw new \RuntimeException("hCaptcha verification request failed with status code: ".$statusCode);
            }

            $responseBody = $response->getContent();
            $validationResponseData = json_decode($responseBody, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \RuntimeException("Invalid JSON received from hCaptcha: ".json_last_error_msg());
            }

            if (!isset($validationResponseData['success'])) {
                throw new \RuntimeException("Invalid response format from hCaptcha verification (missing 'success' key).");
            }

            $isValid = $validationResponseData['success'];

            if (!$isValid) {
                $logDetails = ['error-codes' => $validationResponseData['error-codes'] ?? ['validation-failed']];

                $message = $field['captcha_not_validated'] ??
                          $grav['language']->translate('PLUGIN_FORM.ERROR_VALIDATING_HCAPTCHA');

                $grav->fireEvent('onFormValidationError', new \RocketTheme\Toolbox\Event\Event([
                    'form' => $form,
                    'message' => $message
                ]));

                $grav['log']->warning("Form Captcha (hcaptcha) validation failed: [{$uri->route()}] Details: " . json_encode($logDetails));
                return false;
            }

            return true;
        } catch (\Exception $e) {
            $grav['log']->error("Form Captcha (hcaptcha) error: " . $e->getMessage());

            $message = $field['captcha_not_validated'] ??
                      $grav['language']->translate('PLUGIN_FORM.ERROR_VALIDATING_HCAPTCHA');

            $grav->fireEvent('onFormValidationError', new \RocketTheme\Toolbox\Event\Event([
                'form' => $form,
                'message' => $message
            ]));

            return false;
        }
    }

    /**
     * Validate a Turnstile response
     *
     * @param Form $form The form
     * @param array $field The captcha field definition
     * @param array $params Optional parameters
     * @return bool True if validation succeeded
     */
    protected static function validateTurnstile(Form $form, array $field, array $params): bool
    {
        $grav = Grav::instance();
        $uri = $grav['uri'];
        $ip = Uri::ip();

        try {
            $turnstileConfig = $grav['config']->get('plugins.form.turnstile', []);
            $secretKey = $params['turnstile_secret'] ??
                       $field['turnstile_secret'] ??
                       $turnstileConfig['secret_key'] ?? null;

            if (!$secretKey) {
                throw new \RuntimeException("Turnstile secret key not configured.");
            }

            $token = $form->value('cf-turnstile-response', true);

            if (!$token) {
                $message = $field['captcha_not_validated'] ??
                          $grav['language']->translate('PLUGIN_FORM.ERROR_VALIDATING_TURNSTILE');

                $grav->fireEvent('onFormValidationError', new \RocketTheme\Toolbox\Event\Event([
                    'form' => $form,
                    'message' => $message
                ]));

                $grav['log']->warning("Form Captcha (turnstile) validation failed: [{$uri->route()}] Details: missing-input-response");
                return false;
            }

            $client = \Grav\Common\HTTP\Client::getClient();
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
                $logDetails = ['error-codes' => $content['error-codes'] ?? ['validation-failed']];

                $message = $field['captcha_not_validated'] ??
                          $grav['language']->translate('PLUGIN_FORM.ERROR_VALIDATING_TURNSTILE');

                $grav->fireEvent('onFormValidationError', new \RocketTheme\Toolbox\Event\Event([
                    'form' => $form,
                    'message' => $message
                ]));

                $grav['log']->warning("Form Captcha (turnstile) validation failed: [{$uri->route()}] Details: " . json_encode($logDetails));
                return false;
            }

            return true;
        } catch (\Exception $e) {
            $grav['log']->error("Form Captcha (turnstile) error: " . $e->getMessage());

            $message = $field['captcha_not_validated'] ??
                      $grav['language']->translate('PLUGIN_FORM.ERROR_VALIDATING_TURNSTILE');

            $grav->fireEvent('onFormValidationError', new \RocketTheme\Toolbox\Event\Event([
                'form' => $form,
                'message' => $message
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

        // Finally fall back to generic provider message
        if ($provider === 'hcaptcha') {
            return $grav['language']->translate('PLUGIN_FORM.ERROR_VALIDATING_HCAPTCHA');
        } elseif ($provider === 'turnstile') {
            return $grav['language']->translate('PLUGIN_FORM.ERROR_VALIDATING_TURNSTILE');
        } else {
            return $grav['language']->translate('PLUGIN_FORM.ERROR_VALIDATING_CAPTCHA');
        }
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
        $providerName = $field['provider'] ?? 'recaptcha';
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
        $providerName = $field['provider'] ?? 'recaptcha';
        $provider = CaptchaFactory::getProvider($providerName);

        if (!$provider) {
            return 'forms/fields/captcha/default.html.twig';
        }

        return $provider->getTemplateName();
    }
}