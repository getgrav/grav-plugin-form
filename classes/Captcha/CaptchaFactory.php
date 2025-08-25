<?php
namespace Grav\Plugin\Form\Captcha;

use Grav\Common\Grav;

/**
 * Factory for captcha providers
 */
class CaptchaFactory
{
    /** @var array */
    protected static $providers = [];

    /**
     * Register a captcha provider
     *
     * @param string $name Provider name
     * @param string|CaptchaProviderInterface $provider Provider class or instance
     * @return void
     */
    public static function registerProvider(string $name, $provider): void
    {
        // If it's a class name, instantiate it
        if (is_string($provider) && class_exists($provider)) {
            $provider = new $provider();
        }

        if (!$provider instanceof CaptchaProviderInterface) {
            Grav::instance()['log']->error("Cannot register captcha provider '{$name}': Provider must implement CaptchaProviderInterface");
            return;
        }

        self::$providers[$name] = $provider;
//        Grav::instance()['log']->debug("Registered captcha provider: {$name}");
    }

    /**
     * Check if a provider is registered
     *
     * @param string $name Provider name
     * @return bool
     */
    public static function hasProvider(string $name): bool
    {
        return isset(self::$providers[$name]);
    }

    /**
     * Get a provider by name
     *
     * @param string $name Provider name
     * @return CaptchaProviderInterface|null Provider instance or null if not found
     */
    public static function getProvider(string $name): ?CaptchaProviderInterface
    {
        return self::$providers[$name] ?? null;
    }

    /**
     * Get all registered providers
     *
     * @return array
     */
    public static function getProviders(): array
    {
        return self::$providers;
    }

    /**
     * Register all default captcha providers
     *
     * @return void
     */
    public static function registerDefaultProviders(): void
    {
        // Register built-in providers
        self::registerProvider('recaptcha', new ReCaptchaProvider());
        self::registerProvider('turnstile', new TurnstileProvider());
        self::registerProvider('basic-captcha', new BasicCaptchaProvider());

        // Log the registration
//        Grav::instance()['log']->debug('Registered default captcha providers');
    }
}