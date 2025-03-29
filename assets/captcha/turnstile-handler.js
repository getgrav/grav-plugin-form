(function() {
    'use strict';

    // Register the handler with the form system when it's ready
    const registerTurnstileHandler = function() {
        if (window.GravFormXHR && window.GravFormXHR.captcha) {
            window.GravFormXHR.captcha.register('turnstile', {
                reset: function(container, form) {
                    const formId = form.id;
                    const containerId = `cf-turnstile-${formId}`;
                    const widgetContainer = document.getElementById(containerId);

                    if (!widgetContainer) {
                        console.warn(`Turnstile container #${containerId} not found.`);
                        return;
                    }

                    // Get configuration from data attributes
                    const parentContainer = container.closest('[data-captcha-provider="turnstile"]');
                    const sitekey = parentContainer ? parentContainer.dataset.sitekey : null;

                    if (!sitekey) {
                        console.warn('Cannot reinitialize Turnstile - missing sitekey attribute');
                        return;
                    }

                    // Clear the container to ensure fresh rendering
                    widgetContainer.innerHTML = '';

                    console.log(`Re-rendering Turnstile widget for form: ${formId}`);

                    // Check if Turnstile API is available
                    if (typeof window.turnstile !== 'undefined') {
                        try {
                            // Reset any existing widgets
                            try {
                                window.turnstile.reset(containerId);
                            } catch (e) {
                                // Ignore reset errors, we'll re-render anyway
                            }

                            // Render with a slight delay to ensure DOM is settled
                            setTimeout(() => {
                                window.turnstile.render(`#${containerId}`, {
                                    sitekey: sitekey,
                                    theme: parentContainer ? (parentContainer.dataset.theme || 'light') : 'light',
                                    callback: function(token) {
                                        console.log(`Turnstile verification completed for form: ${formId} with token:`, token.substring(0, 10) + '...');

                                        // Create or update hidden input for token
                                        let tokenInput = form.querySelector('input[name="cf-turnstile-response"]');
                                        if (!tokenInput) {
                                            console.log('Creating new hidden input for Turnstile token');
                                            tokenInput = document.createElement('input');
                                            tokenInput.type = 'hidden';
                                            tokenInput.name = 'cf-turnstile-response';
                                            form.appendChild(tokenInput);
                                        } else {
                                            console.log('Updating existing hidden input for Turnstile token');
                                        }
                                        tokenInput.value = token;

                                        // Also add a debug attribute
                                        form.setAttribute('data-turnstile-verified', 'true');
                                    },
                                    'expired-callback': function() {
                                        console.log(`Turnstile token expired for form: ${formId}`);
                                    },
                                    'error-callback': function(error) {
                                        console.error(`Turnstile error for form ${formId}: ${error}`);
                                    }
                                });
                            }, 100);
                        } catch (e) {
                            console.error(`Error rendering Turnstile widget: ${e.message}`);
                            widgetContainer.innerHTML = '<p style="color:red;">Error initializing Turnstile.</p>';
                        }
                    } else {
                        console.warn('Turnstile API not available. Attempting to reload...');

                        // Remove existing script if any
                        const existingScript = document.querySelector('script[src*="challenges.cloudflare.com/turnstile/v0/api.js"]');
                        if (existingScript) {
                            existingScript.parentNode.removeChild(existingScript);
                        }

                        // Create new script element
                        const script = document.createElement('script');
                        script.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';
                        script.async = true;
                        script.defer = true;
                        script.onload = function() {
                            console.log('Turnstile API loaded, retrying widget render...');
                            setTimeout(() => {
                                const retryContainer = document.querySelector('[data-captcha-provider="turnstile"]');
                                if (retryContainer && form) {
                                    window.GravFormXHR.captcha.getProvider('turnstile').reset(retryContainer, form);
                                }
                            }, 200);
                        };
                        document.head.appendChild(script);
                    }
                }
            });
            console.log('Turnstile XHR handler registered successfully');
        } else {
            console.error('GravFormXHR.captcha not found. Make sure the Form plugin is loaded correctly.');
        }
    };

    // Try to register the handler immediately if GravFormXHR is already available
    if (window.GravFormXHR && window.GravFormXHR.captcha) {
        registerTurnstileHandler();
    } else {
        // Otherwise, wait for the DOM to be fully loaded
        document.addEventListener('DOMContentLoaded', function() {
            // Give a small delay to ensure GravFormXHR is initialized
            setTimeout(registerTurnstileHandler, 100);
        });
    }
})();
