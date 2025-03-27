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
    function submitFormViaXHR(form) {
        if (!form) {
            console.error('submitFormViaXHR called with invalid form element.');
            return;
        }

        console.log('Initiating XHR submission for form:', form.id);

        // Optional: Add a loading indicator here if desired
        // form.classList.add('submitting');

        var xhr = new XMLHttpRequest();
        xhr.open(form.getAttribute('method') || 'POST', form.getAttribute('action') || window.location.href);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        // Add header for Grav to potentially detect AJAX requests
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

        // --- Handler for successful request (HTTP 200-299) ---
        xhr.onload = function() {
            console.log('XHR request completed for form:', form.id, 'Status:', xhr.status); // form.id is still the original trigger form

            if (xhr.status >= 200 && xhr.status < 300) {
                const formId = form.id; // ID of the form element itself
                const wrapperId = formId + '-wrapper'; // Construct the wrapper ID
                console.log('Target Wrapper ID:', wrapperId);
                // console.log('Raw Response Text:', xhr.responseText);

                // Find the wrapper element currently on the page
                const wrapperElement = document.getElementById(wrapperId);
                if (!wrapperElement) {
                    console.error('Target wrapper element #" + wrapperId + " not found on the page!');
                    // Handle error - maybe update the form itself with an error?
                    form.innerHTML = '<p class="form-message error">Error: Form wrapper missing. Cannot update content.</p>';
                    return;
                }
                console.log('Target wrapper element for update:', wrapperElement);


                const tempDiv = document.createElement('div');
                try {
                    tempDiv.innerHTML = xhr.responseText;
                } catch (e) {
                    console.error("Error parsing response HTML for wrapper:", wrapperId, e);
                    wrapperElement.innerHTML = '<p class="form-message error">An error occurred processing the server response.</p>';
                    return;
                }

                // Find the NEW wrapper element *within the response*
                const newWrapperElement = tempDiv.querySelector('#' + wrapperId);
                console.log('Searching for #' + wrapperId + ' in response. Found element:', newWrapperElement);

                if (newWrapperElement) {
                    console.log('Attempting update using newWrapperElement.innerHTML for wrapper:', wrapperId);
                    try {
                        // *** THIS IS THE KEY CHANGE ***
                        wrapperElement.innerHTML = newWrapperElement.innerHTML;
                        console.log('Update using newWrapperElement.innerHTML SUCCESSFUL for wrapper:', wrapperId);
                    } catch (e) {
                        console.error('Error during wrapperElement.innerHTML update:', e);
                        wrapperElement.innerHTML = '<p class="form-message error">An error occurred updating the form content.</p>';
                    }
                } else {
                    // Fallback: Wrapper ID not found in response. Maybe response is just a message? Or unexpected structure?
                    console.warn('Wrapper element #" + wrapperId + " not found in XHR response. Replacing wrapper content with full response as fallback.');
                    try {
                        wrapperElement.innerHTML = xhr.responseText;
                        console.log('Update using full responseText SUCCESSFUL (fallback) for wrapper:', wrapperId);
                    } catch (e) {
                        console.error('Error during wrapperElement.innerHTML update (fallback):', e);
                        wrapperElement.innerHTML = '<p class="form-message error">An error occurred updating the form content (fallback).</p>';
                    }
                }

                // --- CRUCIAL: Trigger Re-initialization ---
                // AFTER updating the wrapper's content, find the potentially NEW form inside it
                const updatedForm = wrapperElement.querySelector('#' + formId);
                if (updatedForm) {
                    const initializerFuncName = 'initRecaptcha_' + formId;
                    if (window.GravRecaptchaInitializers && typeof window.GravRecaptchaInitializers[initializerFuncName] === 'function') {
                        // Find the container *within the newly updated form content*
                        const newRecaptchaContainer = updatedForm.querySelector('.g-recaptcha-container[data-form-id="' + formId + '"]');
                        if (newRecaptchaContainer) {
                            console.log('Re-initializing reCAPTCHA for form:', formId, 'within updated wrapper');
                            setTimeout(() => {
                                try {
                                    window.GravRecaptchaInitializers[initializerFuncName]();
                                } catch (e) {
                                    console.error("Error running reCAPTCHA initializer for " + formId, e);
                                }
                            }, 0);
                        } else {
                            console.log('reCAPTCHA container not found in updated form for:', formId);
                        }
                    } else {
                        console.log('No reCAPTCHA initializer function found for:', formId);
                    }

                    // **Important for potential re-submission:**
                    // If you need the form to be submittable again via XHR *without* a page refresh,
                    // you might need to re-attach the 'submit' listener here, potentially by calling
                    // setupXHRListener(formId) again, or by modifying setupXHRListener to handle re-attachment.
                    // For now, let's assume it's a one-shot submission per page load.
                    // Example: setupXHRListener(formId); // Re-run setup on the new form if needed

                } else {
                    console.warn("Could not find form #" + formId + " inside the updated wrapper #" + wrapperId);
                }
                // --- END Re-initialization ---

                console.log('Finished processing successful XHR response for wrapper:', wrapperId);

            } else {
                // --- Handle HTTP error responses ---
                // Try to put error inside the wrapper if possible
                const wrapperElement = document.getElementById(form.id + '-wrapper'); // Find wrapper even on error
                const errorTarget = wrapperElement || form; // Fallback to form if wrapper not found
                console.error('Form submission failed for form:', form.id, 'HTTP Status:', xhr.status, xhr.statusText);
                const errorDiv = errorTarget.querySelector('.form-messages') || errorTarget;
                if (errorDiv) {
                    const errorMsg = document.createElement('div');
                    errorMsg.className = 'form-message error';
                    errorMsg.textContent = 'An error occurred during submission (Status: ' + xhr.status + '). Please check the form and try again.';
                    errorDiv.insertBefore(errorMsg, errorDiv.firstChild);
                }
            }
        };

        // --- Handler for network errors ---
        xhr.onerror = function() {
            console.error('Form submission failed due to network error for form:', form.id);

            // Optional: Remove loading indicator
            // form.classList.remove('submitting');

            const errorDiv = form.querySelector('.form-messages') || form;
            if (errorDiv) {
                // errorDiv.innerHTML = ''; // Clear previous messages maybe?
                const errorMsg = document.createElement('div');
                errorMsg.className = 'form-message error';
                errorMsg.textContent = 'A network error occurred. Please check your connection and try again.';
                errorDiv.insertBefore(errorMsg, errorDiv.firstChild);
            }
        };

        // --- Prepare and Send Data ---
        try {
            const formData = new FormData(form);
            const urlEncodedData = new URLSearchParams(formData).toString();
            xhr.send(urlEncodedData);
            console.log('XHR request sent for form:', form.id);
        } catch (e) {
            console.error("Error preparing or sending XHR request for form:", form.id, e);
            // Display error?
            const errorDiv = form.querySelector('.form-messages') || form;
            if (errorDiv) {
                const errorMsg = document.createElement('div');
                errorMsg.className = 'form-message error';
                errorMsg.textContent = 'An unexpected error occurred before sending the form.';
                errorDiv.insertBefore(errorMsg, errorDiv.firstChild);
            }
            // Optional: Remove loading indicator
            // form.classList.remove('submitting');
        }
    }

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
            const directXhrSubmitHandler = function (event) {
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