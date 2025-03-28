<?php
namespace Grav\Plugin\Form\Captcha;

use Grav\Common\Grav;

/**
 * Factory class to get captcha providers
 */
class CaptchaFactory
{
    /** @var array */
    protected static $providers = [];

    /**
     * Register a new captcha provider
     *
     * @param string $name Provider name
     * @param string $className Fully qualified class name
     * @return void
     */
    public static function registerProvider(string $name, string $className): void
    {
        self::$providers[$name] = $className;
    }

    /**
     * Get a captcha provider instance
     *
     * @param string $name Provider name
     * @return CaptchaProviderInterface|null
     */
    public static function getProvider(string $name): ?CaptchaProviderInterface
    {
        // If name not found, check if it matches end of any registered provider
        if (!isset(self::$providers[$name])) {
            foreach (self::$providers as $key => $className) {
                if (strtolower(substr($key, -strlen($name))) === strtolower($name)) {
                    $name = $key;
                    break;
                }
            }
        }

        if (!isset(self::$providers[$name])) {
            return null;
        }

        $className = self::$providers[$name];
        if (class_exists($className)) {
            return new $className();
        }

        return null;
    }

    /**
     * Register default providers
     *
     * @return void
     */
    public static function registerDefaultProviders(): void
    {
        self::registerProvider('recaptcha', ReCaptchaProvider::class);
        self::registerProvider('hcaptcha', HCaptchaProvider::class);
        self::registerProvider('turnstile', TurnstileProvider::class);
        self::registerProvider('basic-captcha', BasicCaptchaProvider::class);
    }

    /**
     * Get provider for a field definition
     *
     * @param array $field Field definition
     * @return CaptchaProviderInterface|null
     */
    public static function getProviderForField(array $field): ?CaptchaProviderInterface
    {
        $provider = $field['provider'] ?? 'recaptcha';
        return self::getProvider($provider);
    }
}