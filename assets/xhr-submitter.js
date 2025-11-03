/**
 * Grav Form XHR Submitter
 *
 * A modular system for handling form submissions via XMLHttpRequest (AJAX).
 * Features include content replacement, captcha handling, and error management.
 */

(function() {
    'use strict';

    // Main namespace
    window.GravFormXHR = {};

    /**
     * Core Module - Contains configuration and utility functions
     */
    const Core = {
        config: {
            debug: false,
            enableLoadingIndicator: false
        },

        /**
         * Configure global settings
         * @param {Object} options - Configuration options
         */
        configure: function(options) {
            Object.assign(this.config, options);
        },

        /**
         * Logger utility
         * @param {string} message - Message to log
         * @param {string} level - Log level ('log', 'warn', 'error')
         */
        log: function(message, level = 'log') {
            if (!this.config.debug) return;

            const validLevels = ['log', 'warn', 'error'];
            const finalLevel = validLevels.includes(level) ? level : 'log';

            console[finalLevel](`[GravFormXHR] ${message}`);
        },

        /**
         * Display an error message within a target element
         * @param {HTMLElement} target - The element to display the error in
         * @param {string} message - The error message
         */
        displayError: function(target, message) {
            const errorMsgContainer = target.querySelector('.form-messages') || target;
            const errorMsg = document.createElement('div');
            errorMsg.className = 'form-message error';
            errorMsg.textContent = message;
            errorMsgContainer.insertBefore(errorMsg, errorMsgContainer.firstChild);
        }
    };

    /**
     * DOM Module - Handles DOM manipulation and form tracking
     */
    const DOM = {
        /**
         * Find a form wrapper by formId
         * @param {string} formId - ID of the form
         * @returns {HTMLElement|null} - The wrapper element or null
         */
        getFormWrapper: function(formId) {
            const wrapperId = formId + '-wrapper';
            return document.getElementById(wrapperId);
        },

        /**
         * Add or remove loading indicators
         * @param {HTMLElement} form - The form element
         * @param {HTMLElement} wrapper - The wrapper element
         * @param {boolean} isLoading - Whether to add or remove loading classes
         */
        updateLoadingState: function(form, wrapper, isLoading) {
            if (!Core.config.enableLoadingIndicator) return;

            if (isLoading) {
                wrapper.classList.add('loading');
                form.classList.add('submitting');
            } else {
                wrapper.classList.remove('loading');
                form.classList.remove('submitting');
            }
        },

        /**
         * Update form content with server response
         * @param {string} responseText - Server response HTML
         * @param {string} wrapperId - ID of the wrapper to update
         * @param {string} formId - ID of the original form
         */
        updateFormContent: function(responseText, wrapperId, formId) {
            const wrapperElement = document.getElementById(wrapperId);
            if (!wrapperElement) {
                console.error(`Cannot update content: Wrapper #${wrapperId} not found`);
                return;
            }

            Core.log(`Updating content for wrapper: ${wrapperId}`);

            // Parse response
            const tempDiv = document.createElement('div');
            try {
                tempDiv.innerHTML = responseText;
            } catch (e) {
                console.error(`Error parsing response HTML for wrapper: ${wrapperId}`, e);
                Core.displayError(wrapperElement, 'An error occurred processing the server response.');
                return;
            }

            try {
                this._updateWrapperContent(tempDiv, wrapperElement, wrapperId, formId);
                this._reinitializeUpdatedForm(wrapperElement, formId);
            } catch (e) {
                console.error(`Error during content update for wrapper ${wrapperId}:`, e);
                Core.displayError(wrapperElement, 'An error occurred updating the form content.');
            }
        },

        /**
                         * Update wrapper content based on response parsing strategy
                         * @private
                         */
        _updateWrapperContent: function(tempDiv, wrapperElement, wrapperId, formId) {
            // Strategy 1: Look for matching wrapper ID in response
            const newWrapperElement = tempDiv.querySelector('#' + wrapperId);

            if (newWrapperElement) {
                wrapperElement.innerHTML = newWrapperElement.innerHTML;
                Core.log(`Update using newWrapperElement.innerHTML SUCCESSFUL for wrapper: ${wrapperId}`);
                return;
            }

            // Strategy 2: Look for matching form ID in response
            const hasMatchingForm = tempDiv.querySelector('#' + formId);

            if (hasMatchingForm) {
                Core.log(`Wrapper element #${wrapperId} not found in XHR response, but found matching form. Using entire response.`);
                wrapperElement.innerHTML = tempDiv.innerHTML;
                return;
            }

            // Strategy 3: Look for toast messages
            const hasToastMessages = tempDiv.querySelector('.toast');

            if (hasToastMessages) {
                Core.log('Found toast messages in response. Updating wrapper with the response.');
                wrapperElement.innerHTML = tempDiv.innerHTML;
                return;
            }

            // Fallback: Use entire response with warning
            Core.log('No matching content found in response. Response may not be valid for this wrapper.', 'warn');
            wrapperElement.innerHTML = tempDiv.innerHTML;
        },

        /**
                         * Reinitialize updated form and its components
                         * @private
                         */
        _reinitializeUpdatedForm: function(wrapperElement, formId) {
            const updatedForm = wrapperElement.querySelector('#' + formId);

            if (updatedForm) {
                Core.log(`Re-running initialization for form ${formId} after update`);

                // First reinitialize any captchas
                CaptchaManager.reinitializeAll(updatedForm);

                // Trigger mutation._grav event for Dropzone and other field reinitializations
                setTimeout(() => {
                    Core.log('Triggering mutation._grav event for field reinitialization');

                    // Trigger using jQuery if available (preferred method for compatibility)
                    if (typeof jQuery !== 'undefined') {
                        jQuery('body').trigger('mutation._grav', [wrapperElement]);
                    } else {
                        // Fallback: dispatch native custom event
                        const event = new CustomEvent('mutation._grav', {
                            detail: { target: wrapperElement },
                            bubbles: true
                        });
                        document.body.dispatchEvent(event);
                    }
                }, 0);

                // Then re-attach the XHR listener
                setTimeout(() => {
                    FormHandler.setupListener(formId);
                }, 10);
            } else {
                // Check if this was a successful submission with just a message
                const hasSuccessMessage = wrapperElement.querySelector('.toast-success, .form-success');

                if (hasSuccessMessage) {
                    Core.log('No form found after update, but success message detected. This appears to be a successful submission.');
                } else {
                    console.warn(`Could not find form #${formId} inside the updated wrapper after update. Cannot re-attach listener/initializers.`);
                }
            }
        }
    };

    /**
                     * XHR Module - Handles XMLHttpRequest operations
                     */
    const XHRManager = {
        /**
                         * Send form data via XHR
                         * @param {HTMLFormElement} form - The form to submit
                         */
        sendFormData: function(form) {
            const formId = form.id;
            const wrapperId = formId + '-wrapper';
            const wrapperElement = DOM.getFormWrapper(formId);

            if (!wrapperElement) {
                console.error(`XHR submission: Target wrapper element #${wrapperId} not found on the page! Cannot proceed.`);
                form.innerHTML = '<p class="form-message error">Error: Form wrapper missing. Cannot update content.</p>';
                return;
            }

            Core.log(`Initiating XHR submission for form: ${formId}, targeting wrapper: ${wrapperId}`);
            DOM.updateLoadingState(form, wrapperElement, true);

            const xhr = new XMLHttpRequest();
            xhr.open(form.getAttribute('method') || 'POST', form.getAttribute('action') || window.location.href);

            // Set Headers
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.setRequestHeader('X-Grav-Form-XHR', 'true');

            // Success handler
            xhr.onload = () => {
                Core.log(`XHR request completed for form: ${formId}, Status: ${xhr.status}`);
                DOM.updateLoadingState(form, wrapperElement, false);

                if (xhr.status >= 200 && xhr.status < 300) {
                    DOM.updateFormContent(xhr.responseText, wrapperId, formId);
                } else {
                    Core.log(`Form submission failed for form: ${formId}, HTTP Status: ${xhr.status} ${xhr.statusText}`, 'error');
                    Core.displayError(wrapperElement, `An error occurred during submission (Status: ${xhr.status}). Please check the form and try again.`);
                }
            };

            // Network error handler
            xhr.onerror = () => {
                Core.log(`Form submission failed due to network error for form: ${formId}`, 'error');
                DOM.updateLoadingState(form, wrapperElement, false);
                Core.displayError(wrapperElement, 'A network error occurred. Please check your connection and try again.');
            };

            // Prepare and send data
            try {
                const formData = new FormData(form);
                const urlEncodedData = new URLSearchParams(formData).toString();
                Core.log(`Sending XHR request for form: ${formId} with custom header X-Grav-Form-XHR`);
                xhr.send(urlEncodedData);
            } catch (e) {
                Core.log(`Error preparing or sending XHR request for form: ${formId}: ${e.message}`, 'error');
                DOM.updateLoadingState(form, wrapperElement, false);
                Core.displayError(wrapperElement, 'An unexpected error occurred before sending the form.');
            }
        }
    };

    /**
                     * CaptchaManager - Handles captcha registration and initialization
                     */
    const CaptchaManager = {
        providers: {},

        /**
                         * Register a captcha provider
                         * @param {string} name - Provider name
                         * @param {object} provider - Provider object with init and reset methods
                         */
        register: function(name, provider) {
            this.providers[name] = provider;
            Core.log(`Registered captcha provider: ${name}`);
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
                         * Get all registered providers
                         * @returns {object} Object containing all providers
                         */
        getProviders: function() {
            return this.providers;
        },

        /**
                         * Reinitialize all captchas in a form
                         * @param {HTMLFormElement} form - Form element containing captchas
                         */
        reinitializeAll: function(form) {
            if (!form || !form.id) return;

            const formId = form.id;
            const containers = form.querySelectorAll('[data-captcha-provider]');

            containers.forEach(container => {
                const providerName = container.dataset.captchaProvider;
                Core.log(`Found captcha container for provider: ${providerName} in form: ${formId}`);

                const provider = this.getProvider(providerName);
                if (provider && typeof provider.reset === 'function') {
                    setTimeout(() => {
                        try {
                            provider.reset(container, form);
                            Core.log(`Successfully reset ${providerName} captcha in form: ${formId}`);
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
                     * FormHandler - Handles form submission and event listeners
                     */
    const FormHandler = {
        /**
                         * Submit a form via XHR
                         * @param {HTMLFormElement} form - Form to submit
                         */
        submitForm: function(form) {
            if (!form || !form.id) {
                console.error('submitForm called with invalid form element or form missing ID.');
                return;
            }

            XHRManager.sendFormData(form);
        },

        /**
                         * Set up XHR submission listener for a form
                         * @param {string} formId - ID of the form
                         */
        setupListener: function(formId) {
            setTimeout(() => {
                const form = document.getElementById(formId);
                if (!form) {
                    Core.log(`XHR Setup (delayed): Form with ID "${formId}" not found.`, 'warn');
                    return;
                }

                // Remove stale marker from previous runs
                delete form.dataset.directXhrListenerAttached;

                // Check if any captcha provider is handling the submission
                const captchaContainer = form.querySelector('[data-captcha-provider][data-intercepts-submit="true"]');

                if (!captchaContainer) {
                    // No intercepting captcha found, attach direct listener
                    this._attachDirectListener(form);
                } else {
                    // Captcha will intercept, don't attach direct listener
                    const providerName = captchaContainer.dataset.captchaProvider;
                    Core.log(`XHR listener deferred: ${providerName} should intercept submit for form: ${formId}`);
                    // Ensure no stale listener marker remains
                    delete form.dataset.directXhrListenerAttached;
                }
            }, 0);
        },

        /**
                         * Attach a direct submit event listener to a form
                         * @private
                         * @param {HTMLFormElement} form - Form element
                         */
        _attachDirectListener: function(form) {
            // Only proceed if XHR is enabled for this form
            if (form.dataset.xhrEnabled !== 'true') {
                Core.log(`XHR not enabled for form: ${form.id}. Skipping direct listener attachment.`);
                return;
            }

            // Check if we already attached a listener
            if (form.dataset.directXhrListenerAttached === 'true') {
                Core.log(`Direct XHR listener already attached for form: ${form.id}`);
                return;
            }

            const directXhrSubmitHandler = (event) => {
                Core.log(`Direct XHR submit handler triggered for form: ${form.id}`);
                event.preventDefault();
                FormHandler.submitForm(form);
            };

            Core.log(`Attaching direct XHR listener for form: ${form.id}`);
            form.addEventListener('submit', directXhrSubmitHandler);
            form.dataset.directXhrListenerAttached = 'true';
        }
    };

    // Initialize basic built-in captcha handlers
    // Other providers will register themselves via separate handler JS files
    const initializeBasicCaptchaHandlers = function() {
        // Basic captcha handler (image refresh etc.)
        CaptchaManager.register('basic-captcha', {
            reset: function(container, form) {
                const formId = form.id;
                const captchaImg = container.querySelector('img');
                const captchaInput = container.querySelector('input[type="text"]');

                if (captchaImg) {
                    // Add a timestamp to force image reload
                    const timestamp = new Date().getTime();
                    const imgSrc = captchaImg.src.split('?')[0] + '?t=' + timestamp;
                    captchaImg.src = imgSrc;

                    // Clear any existing input
                    if (captchaInput) {
                        captchaInput.value = '';
                    }

                    Core.log(`Reset basic-captcha for form: ${formId}`);
                }
            }
        });
    };

    // Initialize basic captcha handlers
    initializeBasicCaptchaHandlers();

    // --- Expose Public API ---

    // Core configuration
    window.GravFormXHR.configure = Core.configure.bind(Core);

    // Form submission
    window.GravFormXHR.submit = FormHandler.submitForm.bind(FormHandler);
    window.GravFormXHR.setupListener = FormHandler.setupListener.bind(FormHandler);

    // Captcha management
    window.GravFormXHR.captcha = CaptchaManager;

    // Legacy support
    window.GravFormXHRSubmitters = {submit: FormHandler.submitForm.bind(FormHandler)};
    window.attachFormSubmitListener = FormHandler.setupListener.bind(FormHandler);

})();
