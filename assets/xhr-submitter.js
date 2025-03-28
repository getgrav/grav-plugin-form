/**
 * Grav Form XHR Submitter
 *
 * Handles submitting forms via XMLHttpRequest (AJAX) when configured.
 * Replaces the content of a designated wrapper element with the server's response.
 * Includes logic to re-initialize captcha widgets after content replacement.
 */

(function() {
    'use strict';

    // Namespace for globally exposed functions and data
    window.GravFormXHR = window.GravFormXHR || {
        // Sub-namespaces for organizing related functionality
        submit: {}, // Form submission handlers
        captcha: {} // Captcha-related handlers
    };

    /**
     * Configuration options (with defaults)
     */
    const config = {
        debug: false, // Enable console logging?
        enableLoadingIndicator: false // Show loading indicators during submission?
    };

    /**
     * Captcha registry to track different captcha providers
     */
    const captchaRegistry = {
        providers: {},

        /**
         * Register a captcha provider
         * @param {string} name - Provider name
         * @param {object} provider - Provider object with init and reset methods
         */
        register: function(name, provider) {
            this.providers[name] = provider;
            log(`Registered captcha provider: ${name}`);
        },

        /**
         * Get a provider by name
         * @param {string} name - Provider name
         * @returns {object|null} Provider object or null if not found
         */
        getProvider: function(name) {
            return this.providers[name] || null;
        },

        /**
         * Re-initialize a captcha in a form after content update
         * @param {HTMLFormElement} form - Updated form element
         */
        reinitialize: function(form) {
            if (!form || !form.id) return;

            const formId = form.id;

            // Find any captcha containers in the form
            const containers = form.querySelectorAll('[data-captcha-provider]');

            containers.forEach(container => {
                const providerName = container.dataset.captchaProvider;
                log(`Found captcha container for provider: ${providerName} in form: ${formId}`);

                const provider = this.getProvider(providerName);
                if (provider && typeof provider.reset === 'function') {
                    // Call the provider's reset method with the container and form
                    setTimeout(() => {
                        try {
                            provider.reset(container, form);
                            log(`Successfully reset ${providerName} captcha in form: ${formId}`);
                        } catch (e) {
                            console.error(`Error resetting ${providerName} captcha:`, e);
                        }
                    }, 0);
                } else {
                    console.warn(`Could not reset captcha provider "${providerName}" - provider not registered or missing reset method`);
                }
            });
        }
    };

    /**
     * Logger utility - logs to console when debug is enabled
     * @param {string} message - Message to log
     * @param {string} level - Log level ('log', 'warn', 'error')
     */
    function log(message, level = 'log') {
        if (!config.debug) return;

        // Make sure we're using a valid console method
        if (typeof console[level] !== 'function') {
            level = 'log'; // Fallback to 'log' if the specified level is not a function
        }

        console[level](`[GravFormXHR] ${message}`);
    }

    /**
     * Performs the actual XHR submission for a given form.
     * Targets a wrapper element (`form.id + '-wrapper'`) for content replacement.
     * @param {HTMLFormElement} form - The form element that triggered the submission.
     */
    function submitFormViaXHR(form) {
        if (!form || !form.id) {
            console.error('submitFormViaXHR called with invalid form element or form missing ID.');
            return;
        }

        const formId = form.id;
        const wrapperId = formId + '-wrapper';
        const wrapperElement = document.getElementById(wrapperId);

        if (!wrapperElement) {
            console.error(`submitFormViaXHR: Target wrapper element #${wrapperId} not found on the page! Cannot proceed.`);
            form.innerHTML = '<p class="form-message error">Error: Form wrapper missing. Cannot update content.</p>';
            return;
        }

        log(`Initiating XHR submission for form: ${formId}, targeting wrapper: ${wrapperId}`);

        // Optional loading indicators
        if (config.enableLoadingIndicator) {
            wrapperElement.classList.add('loading');
            form.classList.add('submitting');
        }

        const xhr = new XMLHttpRequest();
        xhr.open(form.getAttribute('method') || 'POST', form.getAttribute('action') || window.location.href);

        // Set Headers
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.setRequestHeader('X-Grav-Form-XHR', 'true');

        // --- Success handler ---
        xhr.onload = function() {
            log(`XHR request completed for form: ${formId}, Status: ${xhr.status}`);

            // Remove loading indicators if enabled
            if (config.enableLoadingIndicator) {
                wrapperElement.classList.remove('loading');
                form.classList.remove('submitting');
            }

            if (xhr.status >= 200 && xhr.status < 300) {
                // Process successful response
                updateFormContent(xhr.responseText, wrapperId, formId);
            } else {
                // Handle HTTP error responses (e.g., 4xx, 5xx)
                log(`Form submission failed for form: ${formId}, HTTP Status: ${xhr.status} ${xhr.statusText}`, 'error');
                displayError(wrapperElement, `An error occurred during submission (Status: ${xhr.status}). Please check the form and try again.`);
            }
        };

        // --- Network error handler ---
        xhr.onerror = function() {
            log(`Form submission failed due to network error for form: ${formId}`, 'error');

            // Remove loading indicators if enabled
            if (config.enableLoadingIndicator) {
                wrapperElement.classList.remove('loading');
                form.classList.remove('submitting');
            }

            displayError(wrapperElement, 'A network error occurred. Please check your connection and try again.');
        };

        // --- Prepare and Send Data ---
        try {
            const formData = new FormData(form);
            const urlEncodedData = new URLSearchParams(formData).toString();
            log(`Sending XHR request for form: ${formId} with custom header X-Grav-Form-XHR`);
            xhr.send(urlEncodedData);
        } catch (e) {
            log(`Error preparing or sending XHR request for form: ${formId}: ${e.message}`, 'error');
            displayError(wrapperElement, 'An unexpected error occurred before sending the form.');
        }
    }

    /**
     * Updates the form content with the server response
     * @param {string} responseText - The server's response HTML
     * @param {string} wrapperId - The ID of the wrapper element to update
     * @param {string} formId - The ID of the form element
     */
    function updateFormContent(responseText, wrapperId, formId) {
        const wrapperElement = document.getElementById(wrapperId);
        if (!wrapperElement) {
            console.error(`Cannot update content: Wrapper #${wrapperId} not found`);
            return;
        }

        log(`Updating content for wrapper: ${wrapperId}`);

        const tempDiv = document.createElement('div');
        try {
            tempDiv.innerHTML = responseText;
        } catch (e) {
            console.error(`Error parsing response HTML for wrapper: ${wrapperId}`, e);
            displayError(wrapperElement, 'An error occurred processing the server response.');
            return;
        }

        // Look for the updated wrapper element in the response
        const newWrapperElement = tempDiv.querySelector('#' + wrapperId);
        log(`Searching for #${wrapperId} in response. Found element: ${newWrapperElement ? 'yes' : 'no'}`);

        try {
            if (newWrapperElement) {
                // Found the expected wrapper in the response
                wrapperElement.innerHTML = newWrapperElement.innerHTML;
                log(`Update using newWrapperElement.innerHTML SUCCESSFUL for wrapper: ${wrapperId}`);
            } else {
                // Fallback: Check if the entire response is meant to replace the wrapper content
                // This happens when the server returns just the content without the wrapper div

                // Check if the response contains a form with the same ID
                const hasMatchingForm = tempDiv.querySelector('#' + formId);

                if (hasMatchingForm) {
                    log(`Wrapper element #${wrapperId} not found in XHR response, but found matching form. Using entire response as fallback.`);
                    wrapperElement.innerHTML = responseText;
                } else {
                    // If no matching form is found, look for toast messages or other relevant content
                    const hasToastMessages = tempDiv.querySelector('.toast');

                    if (hasToastMessages) {
                        log('Found toast messages in response. Updating wrapper with the response.');
                        wrapperElement.innerHTML = responseText;
                    } else {
                        log('No matching content found in response. Response may not be valid for this wrapper.', 'warn');
                        // Still update with the response, but log a warning
                        wrapperElement.innerHTML = responseText;
                    }
                }

                log(`Update using full responseText SUCCESSFUL (fallback) for wrapper: ${wrapperId}`);
            }

            // --- Find the NEW form element inside the updated wrapper ---
            const updatedForm = wrapperElement.querySelector('#' + formId);
            if (updatedForm) {
                log(`Re-running initialization for form ${formId} after update`);

                // First reinitialize any captchas
                if (typeof captchaRegistry !== 'undefined' && typeof captchaRegistry.reinitialize === 'function') {
                    captchaRegistry.reinitialize(updatedForm);
                }

                // Then re-attach the XHR listener
                setTimeout(() => {
                    if (typeof setupXHRListener === 'function') {
                        setupXHRListener(formId);
                    } else if (window.GravFormXHR && typeof window.GravFormXHR.setupListener === 'function') {
                        window.GravFormXHR.setupListener(formId);
                    } else if (typeof window.attachFormSubmitListener === 'function') {
                        window.attachFormSubmitListener(formId);
                    }
                }, 10);
            } else {
                // If no form is found, check if this was a successful submission with just a message
                const hasSuccessMessage = wrapperElement.querySelector('.toast-success, .form-success');
                if (hasSuccessMessage) {
                    log('No form found after update, but success message detected. This appears to be a successful submission.');
                } else {
                    console.warn(`Could not find form #${formId} inside the updated wrapper #${wrapperId} after update. Cannot re-attach listener/initializers.`);
                }
            }
        } catch (e) {
            console.error(`Error during content update for wrapper ${wrapperId}:`, e);
            displayError(wrapperElement, 'An error occurred updating the form content.');
        }
    }

    /**
     * Display an error message within the target element
     * @param {HTMLElement} target - The element to display the error in
     * @param {string} message - The error message
     */
    function displayError(target, message) {
        const errorMsgContainer = target.querySelector('.form-messages') || target;
        const errorMsg = document.createElement('div');
        errorMsg.className = 'form-message error';
        errorMsg.textContent = message;
        errorMsgContainer.insertBefore(errorMsg, errorMsgContainer.firstChild);
    }

    /**
     * Sets up the event listener for XHR submission on a specific form.
     * Checks if intercepting captchas are present; if so, defers
     * listener attachment.
     * @param {string} formId - The ID attribute of the form element.
     */
    function setupXHRListener(formId) {
        // Use setTimeout to ensure this runs after DOM updates settle
        setTimeout(() => {
            const form = document.getElementById(formId);
            if (!form) {
                log(`XHR Setup (delayed): Form with ID "${formId}" not found.`, 'warn');
                return;
            }

            // Remove potentially stale marker from previous runs
            delete form.dataset.directXhrListenerAttached;

            // Check if any captcha provider is handling the submission
            const captchaContainer = form.querySelector('[data-captcha-provider][data-intercepts-submit="true"]');

            if (!captchaContainer) {
                // No intercepting captcha found, attach the direct XHR listener
                attachDirectSubmitListener(form);
            } else {
                // Captcha will intercept, don't attach direct listener
                const providerName = captchaContainer.dataset.captchaProvider;
                log(`XHR listener deferred: ${providerName} should intercept submit for form: ${formId}`);
                // Ensure no stale listener marker remains
                delete form.dataset.directXhrListenerAttached;
            }
        }, 0);
    }

    /**
     * Attach a direct submit listener to a form
     * @param {HTMLFormElement} form - Form element
     */
    function attachDirectSubmitListener(form) {
        // Only proceed if XHR is enabled for this form
        if (form.dataset.xhrEnabled !== 'true') {
            log(`XHR not enabled for form: ${form.id}. Skipping direct listener attachment.`);
            return;
        }

        // Check if we already attached a listener
        if (form.dataset.directXhrListenerAttached === 'true') {
            log(`Direct XHR listener already attached for form: ${form.id}`);
            return;
        }

        const directXhrSubmitHandler = function(event) {
            log(`Direct XHR submit handler triggered for form: ${form.id}`);
            event.preventDefault();
            submitFormViaXHR(form);
        };

        log(`Attaching direct XHR listener for form: ${form.id}`);
        form.addEventListener('submit', directXhrSubmitHandler);
        form.dataset.directXhrListenerAttached = 'true';
    }

    // --- Register standard captcha providers ---

    // 1. reCAPTCHA (both v2 and v3)
    captchaRegistry.register('recaptcha', {
        /**
         * Reset a reCAPTCHA instance
         * @param {HTMLElement} container - The captcha container
         * @param {HTMLFormElement} form - The parent form
         */
        reset: function(container, form) {
            const formId = form.id;
            const version = container.dataset.recaptchaVersion || '2';
            const initializerFuncName = `initRecaptcha_${formId}`;

            if (window.GravRecaptchaInitializers &&
                typeof window.GravRecaptchaInitializers[initializerFuncName] === 'function') {

                log(`Re-initializing reCAPTCHA v${version} for form: ${formId}`);
                window.GravRecaptchaInitializers[initializerFuncName]();
            } else {
                console.warn(`Cannot reinitialize reCAPTCHA - initializer function ${initializerFuncName} not found`);
            }
        }
    });

    // 2. hCaptcha
    captchaRegistry.register('hcaptcha', {
        /**
         * Reset an hCaptcha instance
         * @param {HTMLElement} container - The captcha container
         * @param {HTMLFormElement} form - The parent form
         */
        reset: function(container, form) {
            const formId = form.id;
            const hcaptchaId = `h-captcha-${formId}`;
            const widgetContainer = document.getElementById(hcaptchaId);

            if (widgetContainer && window.hcaptcha) {
                log(`Re-rendering hCaptcha widget for form: ${formId}`);

                // Get the sitekey from the container
                const sitekey = container.dataset.sitekey;
                if (!sitekey) {
                    console.warn('Cannot reinitialize hCaptcha - missing sitekey attribute');
                    return;
                }

                // Reset any existing widgets in this container
                try {
                    if (widgetContainer.children.length > 0) {
                        window.hcaptcha.reset(widgetContainer);
                    }
                } catch (e) {
                    console.warn(`Error resetting existing hCaptcha widget: ${e.message}`);
                }

                // Render a new widget
                window.hcaptcha.render(hcaptchaId, {
                    sitekey: sitekey,
                    theme: container.dataset.theme || 'light',
                    size: container.dataset.size || 'normal',
                    callback: container.dataset.callbackFunction || null
                });
            } else {
                console.warn('Cannot reinitialize hCaptcha - widget container or global hcaptcha object not found');
            }
        }
    });

    // 3. Turnstile
    captchaRegistry.register('turnstile', {
        /**
         * Reset a Turnstile instance
         * @param {HTMLElement} container - The captcha container
         * @param {HTMLFormElement} form - The parent form
         */
        reset: function(container, form) {
            const formId = form.id;
            const initializerFuncName = `initTurnstile_${formId}`;

            if (window.GravTurnstileInitializers &&
                typeof window.GravTurnstileInitializers[initializerFuncName] === 'function') {

                log(`Re-initializing Turnstile for form: ${formId}`);
                window.GravTurnstileInitializers[initializerFuncName]();
            } else {
                console.warn(`Cannot reinitialize Turnstile - initializer function ${initializerFuncName} not found`);
            }
        }
    });

    // --- Expose necessary functions globally ---

    // Expose the core submit function
    window.GravFormXHR.submit = submitFormViaXHR;

    // Expose the setup function to be called from Twig templates
    window.GravFormXHR.setupListener = setupXHRListener;

    // Legacy support for older templates (deprecated)
    window.GravFormXHRSubmitters = {submit: submitFormViaXHR};
    window.attachFormSubmitListener = setupXHRListener;

    // Configuration accessor
    window.GravFormXHR.configure = function(options) {
        Object.assign(config, options);
    };

    // Captcha registry accessor
    window.GravFormXHR.captcha = captchaRegistry;

})();
