/**
 * Grav Form XHR Submitter
 *
 * Handles submitting forms via XMLHttpRequest (AJAX) when configured.
 * Replaces the form's content with the server's response and
 * attempts to re-initialize specific components like reCAPTCHA.
 */

(function() {
    'use strict';

    // Namespace for globally exposed functions (e.g., for reCAPTCHA script to call)
    window.GravFormXHRSubmitters = window.GravFormXHRSubmitters || {};

    /**
     * Performs the actual XHR submission for a given form.
     * @param {HTMLFormElement} form The form element to submit.
     */
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
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest'); // For server-side detection
        xhr.setRequestHeader('X-Grav-Form-XHR', 'true'); // Our custom header!

        // --- Handler for successful request (HTTP 200-299) ---
        xhr.onload = function() {
            console.log('XHR request completed for form:', formId, 'Status:', xhr.status);

            // Optional: Remove loading indicator
            // wrapperElement.classList.remove('loading');
            // const currentFormInWrapper = wrapperElement.querySelector('#' + formId);
            // if (currentFormInWrapper) currentFormInWrapper.classList.remove('submitting');

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
                            const initializerFuncName = 'initRecaptcha_' + formId;
                            const needsRecaptchaInit = window.GravRecaptchaInitializers && typeof window.GravRecaptchaInitializers[initializerFuncName] === 'function';
                            // Check for container specifically within the newly added form content
                            const recaptchaContainerInNewForm = updatedForm.querySelector('.g-recaptcha-container[data-form-id="' + formId + '"]');

                            if (needsRecaptchaInit && recaptchaContainerInNewForm) {
                                // ReCAPTCHA is present in the new content and needs initialization
                                console.log('Re-initializing reCAPTCHA for form:', formId, 'within updated wrapper');
                                setTimeout(() => { // Use setTimeout for safety after DOM update
                                    try {
                                        window.GravRecaptchaInitializers[initializerFuncName]();
                                        // reCAPTCHA's init function should now add its own intercepting listener
                                    } catch (e) {
                                        console.error('Error running reCAPTCHA initializer for ' + formId, e);
                                    }

                                    // Call setupXHRListener *after* potential async reCAPTCHA init.
                                    // It will check the form again; if reCAPTCHA added its listener,
                                    // setupXHRListener won't add the direct one.
                                    console.log('Calling setupXHRListener for form ' + formId + ' after attempting reCAPTCHA init.');
                                    setupXHRListener(formId); // Re-run setup to ensure correct listener state

                                }, 0); // End setTimeout

                            } else {
                                // No reCAPTCHA initializer found OR no reCAPTCHA container in the updated form content
                                // --> Need to ensure the correct listener (likely direct XHR) is attached.
                                console.log('No intercepting reCAPTCHA detected in updated form ' + formId + '. Re-running listener setup.');
                                // Calling setupXHRListener again handles attaching the direct listener if appropriate
                                setupXHRListener(formId); // Re-run setup on the new form element
                            }

                        } else {
                            console.warn('Could not find form #' + formId + ' inside the updated wrapper #' + wrapperId + ' after update. Cannot re-attach listener.');
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

                        // Even in fallback, try to re-attach listener if the form might be in the response
                        console.log('Attempting listener re-attachment after fallback update for wrapper:', wrapperId);
                        // Find the form ID within the potentially replaced content
                        if (wrapperElement.querySelector('#' + formId)) {
                            setupXHRListener(formId); // Try re-attaching based on the potentially new content
                        } else {
                            console.warn('Form #' + formId + ' not found within wrapper after fallback update. Cannot re-attach listener.');
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
                // Display error inside the wrapper if possible
                const errorTarget = wrapperElement; // Target the wrapper for errors
                const errorMsgContainer = errorTarget.querySelector('.form-messages') || errorTarget; // Find existing message area or use wrapper
                if (errorMsgContainer) {
                    // Clear previous success messages maybe?
                    // const successMessages = errorMsgContainer.querySelectorAll('.form-message.success, .toast-success');
                    // successMessages.forEach(el => el.remove());

                    const errorMsg = document.createElement('div');
                    errorMsg.className = 'form-message error'; // Use theme's error classes
                    errorMsg.textContent = 'An error occurred during submission (Status: ' + xhr.status + '). Please check the form and try again.';
                    // Prepend to show at top
                    errorMsgContainer.insertBefore(errorMsg, errorMsgContainer.firstChild);
                }
            }
        }; // End xhr.onload

        // --- Handler for network errors ---
        xhr.onerror = function() {
            console.error('Form submission failed due to network error for form:', formId);

            // Optional: Remove loading indicator
            // wrapperElement.classList.remove('loading');
            // const currentFormInWrapper = wrapperElement.querySelector('#' + formId);
            // if (currentFormInWrapper) currentFormInWrapper.classList.remove('submitting');

            // Display network error inside the wrapper
            const errorTarget = wrapperElement;
            const errorMsgContainer = errorTarget.querySelector('.form-messages') || errorTarget;
            if (errorMsgContainer) {
                // Clear previous success messages maybe?
                // const successMessages = errorMsgContainer.querySelectorAll('.form-message.success, .toast-success');
                // successMessages.forEach(el => el.remove());

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
            console.log('Sending XHR request for form:', formId, 'with custom header X-Grav-Form-XHR'); //
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
            // Optional: Remove loading indicator
            // wrapperElement.classList.remove('loading');
            // form.classList.remove('submitting');
        }
    } // End submitFormViaXHR function

    /**
     * Sets up the event listener for XHR submission on a specific form.
     * Checks if reCAPTCHA v3/invisible is present; if so, it lets the
     * reCAPTCHA script handle interception. Otherwise, attaches a direct listener.
     * @param {string} formId The ID attribute of the form element.
     */
    function setupXHRListener(formId) {
        var form = document.getElementById(formId);
        if (!form) {
            console.warn('XHR Setup: Form with ID "' + formId + '" not found.');
            return;
        }

        // Check if reCAPTCHA will handle the submission interception
        const recaptchaNeedsIntercept = form.querySelector('.g-recaptcha-container[data-recaptcha-version="3"], .g-recaptcha-container[data-recaptcha-version="2-invisible"]');

        if (!recaptchaNeedsIntercept) {
            // No intercepting reCAPTCHA found, attach the direct XHR listener
            const directXhrSubmitHandler = function(event) {
                console.log('Direct XHR submit handler triggered for form:', formId);
                event.preventDefault(); // Prevent standard browser submission
                submitFormViaXHR(form); // Call the core XHR function
            };

            // Basic check to avoid attaching multiple identical listeners if this runs again (unlikely with DOMContentLoaded)
            if (!form.dataset.directXhrListenerAttached) {
                console.log('Attaching direct XHR listener for form:', formId);
                form.addEventListener('submit', directXhrSubmitHandler);
                form.dataset.directXhrListenerAttached = 'true'; // Mark as attached
            } else {
                console.log('Direct XHR listener already attached for form:', formId);
            }

        } else {
            // Intercepting reCAPTCHA (v3/invisible) is present.
            // Its own script should handle event.preventDefault() and eventually call
            // window.GravFormXHRSubmitters.submit(form) if XHR is enabled.
            console.log('XHR listener deferred: reCAPTCHA should intercept submit for form:', formId);
        }
    }

    // --- Expose necessary functions globally ---

    // Expose the core submit function so reCAPTCHA scripts can call it
    window.GravFormXHRSubmitters.submit = submitFormViaXHR;

    // Expose the setup function to be called from inline script in Twig
    // Note: This function needs to be defined before it's potentially called
    // by the inline script triggered by DOMContentLoaded.
    // We make it global temporarily for the inline script to find it.
    // An alternative is to manage initialization differently, but this is common.
    window.attachFormSubmitListener = setupXHRListener;

    // Clean up the global scope slightly after initial setup phase (optional)
    // We keep GravFormXHRSubmitters.submit exposed
    /*
    document.addEventListener('DOMContentLoaded', () => {
        if (window.attachFormSubmitListener === setupXHRListener) {
            // delete window.attachFormSubmitListener; // Remove if no longer needed after DOM load
        }
    });
    */

})(); // End IIFE
