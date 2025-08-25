(function() {
    'use strict';

    // Register the handler with the form system when it's ready
    const registerRecaptchaHandler = function() {
        if (window.GravFormXHR && window.GravFormXHR.captcha) {
            window.GravFormXHR.captcha.register('recaptcha', {
                reset: function(container, form) {
                    if (!form || !form.id) {
                        console.warn('Cannot reset reCAPTCHA: form is invalid or missing ID');
                        return;
                    }

                    const formId = form.id;
                    console.log(`Attempting to reset reCAPTCHA for form: ${formId}`);

                    // First try the expected ID pattern from the Twig template
                    const recaptchaId = `g-recaptcha-${formId}`;
                    // We need to look more flexibly for the container
                    let widgetContainer = document.getElementById(recaptchaId);

                    // If not found by ID, look for the div inside the captcha provider container
                    if (!widgetContainer) {
                        // Try to find it inside the captcha provider container
                        widgetContainer = container.querySelector('.g-recaptcha');

                        if (!widgetContainer) {
                            // If that fails, look more broadly in the form
                            widgetContainer = form.querySelector('.g-recaptcha');

                            if (!widgetContainer) {
                                // Last resort - create a new container if needed
                                console.warn(`reCAPTCHA container #${recaptchaId} not found. Creating a new one.`);
                                widgetContainer = document.createElement('div');
                                widgetContainer.id = recaptchaId;
                                widgetContainer.className = 'g-recaptcha';
                                container.appendChild(widgetContainer);
                            }
                        }
                    }

                    console.log(`Found reCAPTCHA container for form: ${formId}`);

                    // Get configuration from data attributes
                    const parentContainer = container.closest('[data-captcha-provider="recaptcha"]');
                    if (!parentContainer) {
                        console.warn('Cannot find reCAPTCHA parent container with data-captcha-provider attribute.');
                        return;
                    }

                    const sitekey = parentContainer.dataset.sitekey;
                    const version = parentContainer.dataset.version || '2-checkbox';
                    const isV3 = version.startsWith('3');
                    const isInvisible = version === '2-invisible';

                    if (!sitekey) {
                        console.warn('Cannot reinitialize reCAPTCHA - missing sitekey attribute');
                        return;
                    }

                    console.log(`Re-rendering reCAPTCHA widget for form: ${formId}, version: ${version}`);

                    // Handle V3 reCAPTCHA differently
                    if (isV3) {
                        try {
                            // For v3, we don't need to reset anything visible, just make sure we have the API
                            if (typeof grecaptcha !== 'undefined' && typeof grecaptcha.execute === 'function') {
                                // Create a new execution context for the form
                                const actionName = `form_${formId}`;
                                const tokenInput = form.querySelector('input[name="token"]') ||
                                                 form.querySelector('input[name="data[token]"]');
                                const actionInput = form.querySelector('input[name="action"]') ||
                                                  form.querySelector('input[name="data[action]"]');

                                if (tokenInput && actionInput) {
                                    // Clear previous token
                                    tokenInput.value = '';

                                    // Set the action name
                                    actionInput.value = actionName;

                                    console.log(`reCAPTCHA v3 ready for execution on form: ${formId}`);
                                } else {
                                    console.warn(`Cannot find token or action inputs for reCAPTCHA v3 in form: ${formId}`);
                                }
                            } else {
                                console.warn('reCAPTCHA v3 API not properly loaded.');
                            }
                        } catch (e) {
                            console.error(`Error setting up reCAPTCHA v3: ${e.message}`);
                        }
                        return;
                    }

                    // For v2, handle visible widget reset
                    // Clear the container to ensure fresh rendering
                    widgetContainer.innerHTML = '';

                    // Check if reCAPTCHA API is available
                    if (typeof grecaptcha !== 'undefined' && typeof grecaptcha.render === 'function') {
                        try {
                            // Render with a slight delay to ensure DOM is settled
                            setTimeout(() => {
                                grecaptcha.render(widgetContainer.id || widgetContainer, {
                                    'sitekey': sitekey,
                                    'theme': parentContainer.dataset.theme || 'light',
                                    'size': isInvisible ? 'invisible' : 'normal',
                                    'callback': function(token) {
                                        console.log(`reCAPTCHA verification completed for form: ${formId}`);

                                        // If it's invisible reCAPTCHA, submit the form automatically
                                        if (isInvisible && window.GravFormXHR && typeof window.GravFormXHR.submit === 'function') {
                                            window.GravFormXHR.submit(form);
                                        }
                                    }
                                });
                                console.log(`Successfully rendered reCAPTCHA for form: ${formId}`);
                            }, 100);
                        } catch (e) {
                            console.error(`Error rendering reCAPTCHA widget: ${e.message}`);
                            widgetContainer.innerHTML = '<p style="color:red;">Error initializing reCAPTCHA.</p>';
                        }
                    } else {
                        console.warn('reCAPTCHA API not available. Attempting to reload...');

                        // Remove existing script if any
                        const existingScript = document.querySelector('script[src*="google.com/recaptcha/api.js"]');
                        if (existingScript) {
                            existingScript.parentNode.removeChild(existingScript);
                        }

                        // Create new script element
                        const script = document.createElement('script');
                        script.src = `https://www.google.com/recaptcha/api.js${isV3 ? '?render=' + sitekey : ''}`;
                        script.async = true;
                        script.defer = true;
                        script.onload = function() {
                            console.log('reCAPTCHA API loaded, retrying widget render...');
                            setTimeout(() => {
                                const retryContainer = document.querySelector(`[data-captcha-provider="recaptcha"]`);
                                if (retryContainer && form) {
                                    window.GravFormXHR.captcha.getProvider('recaptcha').reset(retryContainer, form);
                                }
                            }, 200);
                        };
                        document.head.appendChild(script);
                    }
                }
            });
            console.log('reCAPTCHA XHR handler registered successfully');
        } else {
            console.error('GravFormXHR.captcha not found. Make sure the Form plugin is loaded correctly.');
        }
    };

    // Try to register the handler immediately if GravFormXHR is already available
    if (window.GravFormXHR && window.GravFormXHR.captcha) {
        registerRecaptchaHandler();
    } else {
        // Otherwise, wait for the DOM to be fully loaded
        document.addEventListener('DOMContentLoaded', function() {
            // Give a small delay to ensure GravFormXHR is initialized
            setTimeout(registerRecaptchaHandler, 100);
        });
    }
})();
