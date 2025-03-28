/**
 * Grav Form XHR Submitter
 *
 * Handles submitting forms via XMLHttpRequest (AJAX) when configured.
 * Replaces the content of a designated wrapper element with the server's response.
 * Includes logic to re-initialize Google reCAPTCHA and Cloudflare Turnstile widgets
 * after the content replacement.
 */

(function() {
    'use strict';

    // Namespace for globally exposed functions (e.g., for reCAPTCHA script to call)
    window.GravFormXHRSubmitters = window.GravFormXHRSubmitters || {};

    /**
     * Performs the actual XHR submission for a given form.
     * Targets a wrapper element (`form.id + '-wrapper'`) for content replacement.
     * @param {HTMLFormElement} form The form element that triggered the submission.
     */
    function submitFormViaXHR(form) {
        if (!form || !form.id) { // Ensure form and form.id exist
            console.error('submitFormViaXHR called with invalid form element or form missing ID.');
            return;
        }

        const formId = form.id; // Get form ID early
        const wrapperId = formId + '-wrapper'; // Construct the wrapper ID
        const wrapperElement = document.getElementById(wrapperId); // Find wrapper on page

        if (!wrapperElement) {
            console.error('submitFormViaXHR: Target wrapper element #" + wrapperId + " not found on the page! Cannot proceed.');
            // Optionally display an error within the form itself as a last resort
            form.innerHTML = '<p class="form-message error">Error: Form wrapper missing. Cannot update content.</p>';
            return;
        }

        console.log('Initiating XHR submission for form:', formId, 'targeting wrapper:', wrapperId);

        // Optional: Add a loading indicator to the wrapper or form
        // wrapperElement.classList.add('loading');
        // form.classList.add('submitting');

        var xhr = new XMLHttpRequest();
        xhr.open(form.getAttribute('method') || 'POST', form.getAttribute('action') || window.location.href);

        // Set Headers AFTER open() but BEFORE send()
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest'); // Standard header
        xhr.setRequestHeader('X-Grav-Form-XHR', 'true'); // Custom header for server-side check

        // --- Handler for successful request (HTTP 200-299) ---
        xhr.onload = function() {
            console.log('XHR request completed for form:', formId, 'Status:', xhr.status);

            // Optional: Remove loading indicator
            // wrapperElement.classList.remove('loading');

            if (xhr.status >= 200 && xhr.status < 300) {
                // Response received successfully
                console.log('Target Wrapper ID:', wrapperId);
                // console.log('Raw Response Text:', xhr.responseText); // Debugging

                const tempDiv = document.createElement('div');
                try {
                    tempDiv.innerHTML = xhr.responseText;
                } catch (e) {
                    console.error('Error parsing response HTML for wrapper:', wrapperId, e);
                    wrapperElement.innerHTML = '<p class="form-message error">An error occurred processing the server response.</p>';
                    return; // Stop processing
                }

                // Find the NEW wrapper element *within the response*
                const newWrapperElement = tempDiv.querySelector('#' + wrapperId);
                console.log('Searching for #' + wrapperId + ' in response. Found element:', newWrapperElement);

                if (newWrapperElement) {
                    // Found the expected wrapper in the response
                    console.log('Attempting update using newWrapperElement.innerHTML for wrapper:', wrapperId);
                    try {
                        // *** Replace content of the existing wrapper ***
                        wrapperElement.innerHTML = newWrapperElement.innerHTML;
                        console.log('Update using newWrapperElement.innerHTML SUCCESSFUL for wrapper:', wrapperId);

                        // --- Listener Re-attachment & Re-initialization ---
                        // Find the NEW form element *inside the updated wrapper*
                        const updatedForm = wrapperElement.querySelector('#' + formId);
                        if (updatedForm) {
                            // --- Check for V3/V2-Inv reCAPTCHA Initializer ---
                            const recaptchaLegacyInitializerFunc = 'initRecaptcha_' + formId; // Name used in the complex JS block
                            if (window.GravRecaptchaInitializers && typeof window.GravRecaptchaInitializers[recaptchaLegacyInitializerFunc] === 'function') {
                                // Check if the specific container for these versions exists
                                const recaptchaLegacyContainer = updatedForm.querySelector('.g-recaptcha-container[data-form-id="' + formId + '"][data-recaptcha-version]'); // More specific selector
                                if (recaptchaLegacyContainer && (recaptchaLegacyContainer.dataset.recaptchaVersion == 3 || recaptchaLegacyContainer.dataset.recaptchaVersion == '2-invisible')) {
                                    console.log('Re-initializing V3/V2-Inv reCAPTCHA for form:', formId);
                                    setTimeout(() => {
                                        try {
                                            window.GravRecaptchaInitializers[recaptchaLegacyInitializerFunc]();
                                        } catch (e) {
                                            console.error('Error running V3/V2-Inv reCAPTCHA initializer for ' + formId, e);
                                        }
                                    }, 0);
                                }
                            }

                            // --- Check for Explicit Captcha Initializer (V2 Checkbox / hCaptcha) ---
                            const explicitCaptchaInitializerFunc = 'initExplicitCaptcha_' + formId;
                            if (window.GravExplicitCaptchaInitializers && typeof window.GravExplicitCaptchaInitializers[explicitCaptchaInitializerFunc] === 'function') {
                                // Check if a container for either explicit type exists
                                const explicitCaptchaContainer = updatedForm.querySelector('#g-recaptcha-' + formId + ', #h-captcha-' + formId);
                                if (explicitCaptchaContainer) {
                                    console.log('Re-initializing Explicit Captcha (V2 Checkbox / hCaptcha) for form:', formId);
                                    setTimeout(() => {
                                        try {
                                            window.GravExplicitCaptchaInitializers[explicitCaptchaInitializerFunc]();
                                        } catch (e) {
                                            console.error('Error running Explicit Captcha initializer for ' + formId, e);
                                        }
                                    }, 0);
                                }
                            }

                            // --- Check for Turnstile Initializer (Keep separate) ---
                            const turnstileInitializerFuncName = 'initTurnstile_' + formId;
                            if (window.GravTurnstileInitializers && typeof window.GravTurnstileInitializers[turnstileInitializerFuncName] === 'function') {
                                const turnstileContainerInNewForm = updatedForm.querySelector('#cf-turnstile-' + formId);
                                if (turnstileContainerInNewForm) {
                                    console.log('Re-initializing Turnstile for form:', formId);
                                    setTimeout(() => { /* ... call initializer ... */
                                    }, 0);
                                }
                            }

                            // --- Ensure XHR Listener is correctly setup ---
                            console.log('Re-running listener setup for form ' + formId + ' after potential captcha init.');
                            setTimeout(() => setupXHRListener(formId), 10);

                        } else {
                            console.warn('Could not find form #' + formId + ' inside the updated wrapper #' + wrapperId + ' after update. Cannot re-attach listener/initializers.');
                        }
                        // --- END Listener Re-attachment & Re-initialization ---

                    } catch (e) {
                        console.error('Error during wrapperElement.innerHTML update:', e);
                        wrapperElement.innerHTML = '<p class="form-message error">An error occurred updating the form content.</p>';
                    }
                } else {
                    // Fallback: Wrapper ID not found in response. Replace wrapper content with full response.
                    console.warn('Wrapper element #" + wrapperId + " not found in XHR response. Replacing wrapper content with full response as fallback.');
                    try {
                        wrapperElement.innerHTML = xhr.responseText;
                        console.log('Update using full responseText SUCCESSFUL (fallback) for wrapper:', wrapperId);

                        // Attempt re-init/re-attach after fallback
                        const updatedFormInFallback = wrapperElement.querySelector('#' + formId);
                        if (updatedFormInFallback) {
                            console.log('Attempting listener/initializer re-attachment after fallback update for wrapper:', wrapperId);

                            // Check/Call Turnstile Initializer in fallback
                            const turnstileInitializerFuncName = 'initTurnstile_' + formId;
                            if (window.GravTurnstileInitializers && typeof window.GravTurnstileInitializers[turnstileInitializerFuncName] === 'function') {
                                const turnstileContainerInFallback = updatedFormInFallback.querySelector('#cf-turnstile-' + formId);
                                if (turnstileContainerInFallback) {
                                    setTimeout(() => {
                                        try {
                                            window.GravTurnstileInitializers[turnstileInitializerFuncName]();
                                        } catch (e) {
                                            console.error(e);
                                        }
                                    }, 0);
                                }
                            }
                            // Check/Call reCAPTCHA Initializer in fallback (if applicable)
                            const recaptchaInitializerFuncName = 'initRecaptcha_' + formId;
                            if (window.GravRecaptchaInitializers && typeof window.GravRecaptchaInitializers[recaptchaInitializerFuncName] === 'function') {
                                const recaptchaContainerInFallback = updatedFormInFallback.querySelector('.g-recaptcha-container[data-form-id="' + formId + '"]');
                                if (recaptchaContainerInFallback) {
                                    setTimeout(() => {
                                        try {
                                            window.GravRecaptchaInitializers[recaptchaInitializerFuncName]();
                                        } catch (e) {
                                            console.error(e);
                                        }
                                    }, 0);
                                }
                            }

                            // Re-attach listener after fallback
                            setTimeout(() => setupXHRListener(formId), 10);

                        } else {
                            console.warn('Form #' + formId + ' not found within wrapper after fallback update. Cannot re-attach listener/initializers.');
                        }
                    } catch (e) {
                        console.error('Error during wrapperElement.innerHTML update (fallback):', e);
                        wrapperElement.innerHTML = '<p class="form-message error">An error occurred updating the form content (fallback).</p>';
                    }
                }

                console.log('Finished processing successful XHR response for wrapper:', wrapperId);

            } else {
                // --- Handle HTTP error responses (e.g., 4xx, 5xx) ---
                console.error('Form submission failed for form:', formId, 'HTTP Status:', xhr.status, xhr.statusText);
                // Display error inside the wrapper
                const errorTarget = wrapperElement; // Target the wrapper for errors
                const errorMsgContainer = errorTarget.querySelector('.form-messages') || errorTarget;
                if (errorMsgContainer) {
                    const errorMsg = document.createElement('div');
                    errorMsg.className = 'form-message error'; // Use theme's error classes
                    errorMsg.textContent = 'An error occurred during submission (Status: ' + xhr.status + '). Please check the form and try again.';
                    errorMsgContainer.insertBefore(errorMsg, errorMsgContainer.firstChild);
                }
            }
        }; // End xhr.onload

        // --- Handler for network errors ---
        xhr.onerror = function() {
            console.error('Form submission failed due to network error for form:', formId);
            // Optional: Remove loading indicator
            // wrapperElement.classList.remove('loading');

            // Display network error inside the wrapper
            const errorTarget = wrapperElement;
            const errorMsgContainer = errorTarget.querySelector('.form-messages') || errorTarget;
            if (errorMsgContainer) {
                const errorMsg = document.createElement('div');
                errorMsg.className = 'form-message error';
                errorMsg.textContent = 'A network error occurred. Please check your connection and try again.';
                errorMsgContainer.insertBefore(errorMsg, errorMsgContainer.firstChild);
            }
        }; // End xhr.onerror

        // --- Prepare and Send Data ---
        try {
            const formData = new FormData(form);
            const urlEncodedData = new URLSearchParams(formData).toString();
            console.log('Sending XHR request for form:', formId, 'with custom header X-Grav-Form-XHR');
            xhr.send(urlEncodedData);
        } catch (e) {
            console.error('Error preparing or sending XHR request for form:', formId, e);
            // Display error?
            const errorTarget = wrapperElement;
            const errorMsgContainer = errorTarget.querySelector('.form-messages') || errorTarget;
            if (errorMsgContainer) {
                const errorMsg = document.createElement('div');
                errorMsg.className = 'form-message error';
                errorMsg.textContent = 'An unexpected error occurred before sending the form.';
                errorMsgContainer.insertBefore(errorMsg, errorMsgContainer.firstChild);
            }
        }
    } // End submitFormViaXHR function

    /**
     * Sets up the event listener for XHR submission on a specific form.
     * Checks if intercepting reCAPTCHA (v3/invisible) is present; if so, defers
     * listener attachment to the reCAPTCHA script. Otherwise, attaches a direct listener.
     * @param {string} formId The ID attribute of the form element.
     */
    function setupXHRListener(formId) {
        // Use setTimeout to ensure this runs after potential DOM updates settle
        // and after captcha initializers might have run and added their elements/listeners.
        setTimeout(() => {
            var form = document.getElementById(formId);
            if (!form) {
                console.warn('XHR Setup (delayed): Form with ID "' + formId + '" not found.');
                return;
            }
            // Remove potentially stale marker from previous runs
            delete form.dataset.directXhrListenerAttached;

            // Check if reCAPTCHA v3/invisible script already added its listener
            // (We check for the presence of the container, assuming the init script runs if container exists)
            const recaptchaV3InvisibleContainer = form.querySelector('.g-recaptcha-container[data-recaptcha-version="3"], .g-recaptcha-container[data-recaptcha-version="2-invisible"]');

            if (!recaptchaV3InvisibleContainer) {
                // No intercepting reCAPTCHA found, attach the direct XHR listener
                const directXhrSubmitHandler = function(event) {
                    console.log('Direct XHR submit handler triggered for form:', formId);
                    event.preventDefault(); // Prevent standard browser submission
                    submitFormViaXHR(form); // Call the core XHR function
                };

                // Ensure the form is actually configured for XHR before adding listener
                if (form.dataset.xhrEnabled === 'true') {
                    // Basic check to avoid attaching multiple identical listeners if this somehow runs multiple times rapidly
                    // We identify listener by checking a dataset flag WE set.
                    if (!form.dataset.directXhrListenerAttached) {
                        console.log('Attaching direct XHR listener for form:', formId);
                        // IMPORTANT: Remove previous listener if stored? This is hard without storing the exact function ref.
                        // Relying on finding only one form by ID and adding listener once after update.
                        form.addEventListener('submit', directXhrSubmitHandler);
                        form.dataset.directXhrListenerAttached = 'true'; // Mark as attached
                    } else {
                        console.log('Direct XHR listener should already be attached for form:', formId);
                    }
                } else {
                    console.log('XHR not enabled for form:', formId, '. Skipping direct listener attachment.');
                    // Ensure no stale listener marker remains
                    delete form.dataset.directXhrListenerAttached;
                }

            } else {
                // Intercepting reCAPTCHA (v3/invisible) is present.
                // Its own script should handle event.preventDefault() and eventually call
                // window.GravFormXHRSubmitters.submit(form) if XHR is enabled.
                console.log('XHR listener deferred: reCAPTCHA should intercept submit for form:', formId);
                // Ensure no stale listener marker remains
                delete form.dataset.directXhrListenerAttached;
            }
        }, 0); // Tiny delay to run after current execution stack clears
    } // End setupXHRListener function

    // --- Expose necessary functions globally ---

    // Expose the core submit function so reCAPTCHA/other scripts can call it
    window.GravFormXHRSubmitters.submit = submitFormViaXHR;

    // Expose the setup function to be called from inline script in Twig layout
    window.attachFormSubmitListener = setupXHRListener;

})(); // End IIFE
