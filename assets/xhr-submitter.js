function submitFormViaXHR(form) {
    // Submit the form via Ajax
    var xhr = new XMLHttpRequest();
    xhr.open(form.getAttribute('method'), form.getAttribute('action'));
    // Add header for Grav to detect AJAX requests if needed
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');

    xhr.onload = function() {
        if (xhr.status === 200) {
            const formId = form.id; // Store ID before potential replacement
            const parent = form.parentNode; // Get parent in case form itself is replaced
            // Create a temporary div to parse the response
            const tempDiv = document.createElement('div');
            tempDiv.innerHTML = xhr.responseText;
            const newFormElement = tempDiv.querySelector('#' + formId); // Find the new form element in the response

            if (newFormElement) {
                form.innerHTML = newFormElement.innerHTML; // Replace only innerHTML as before

                // --- CRUCIAL: Re-initialization ---
                // Find the reCAPTCHA container in the *new* content
                const newRecaptchaContainer = form.querySelector('.g-recaptcha-container[data-form-id="' + formId + '"]');
                if (newRecaptchaContainer) {
                    // Construct the initializer function name
                    const initializerFuncName = 'initRecaptcha_' + formId;
                    // Check if the initializer function exists and call it
                    if (window.GravRecaptchaInitializers && typeof window.GravRecaptchaInitializers[initializerFuncName] === 'function') {
                        console.log('Re-initializing reCAPTCHA for form:', formId);
                        // Use setTimeout to ensure the DOM is fully updated
                        setTimeout(() => window.GravRecaptchaInitializers[initializerFuncName](), 0);
                    } else {
                        console.warn('reCAPTCHA initializer function not found:', initializerFuncName);
                    }
                }
                // --- END Re-initialization ---

                // Optional: Re-attach the base XHR listener *if* no recaptcha listener took over
                // This logic might be complex. Relying on recaptcha script to always re-attach is simpler.
                // setupXHRListener(formId); // Re-attach the listener to the new form content if needed.

            } else {
                // Fallback or error handling if the form ID wasn't found in the response
                form.innerHTML = xhr.responseText; // Original behavior as fallback
                console.warn('Form element with ID "' + formId + '" not found in XHR response. Replacing entire content.');
            }

        } else {
            // Handle HTTP error responses
            console.error('Form submission failed with status: ' + xhr.status);
            // Optionally display a generic error message to the user within the form
            const errorDiv = form.querySelector('.form-messages') || form; // Find message div or use form itself
            if (errorDiv) {
                const errorMsg = document.createElement('div');
                errorMsg.className = 'form-message error'; // Use your theme's error classes
                errorMsg.textContent = 'An error occurred during submission (' + xhr.status + '). Please try again.';
                errorDiv.prepend(errorMsg); // Add error message
            }
        }
    };

    xhr.onerror = function() {
        // Handle network errors
        console.error('Form submission failed due to network error.');
        const errorDiv = form.querySelector('.form-messages') || form;
        if (errorDiv) {
            const errorMsg = document.createElement('div');
            errorMsg.className = 'form-message error';
            errorMsg.textContent = 'A network error occurred. Please check your connection and try again.';
            errorDiv.prepend(errorMsg);
        }
    };

    xhr.send(new URLSearchParams(new FormData(form)).toString());
}

function setupXHRListener(formId) {
    var form = document.getElementById(formId);
    if (!form) {
        console.warn('XHR Setup: Form with ID "' + formId + '" not found.');
        return;
    }

    // Check if reCAPTCHA will handle the submission interception
    // We look for the container added in captcha.html.twig for v3 or v2-invisible
    const recaptchaNeedsIntercept = form.querySelector('.g-recaptcha-container[data-recaptcha-version="3"], .g-recaptcha-container[data-recaptcha-version="2-invisible"]');

    if (!recaptchaNeedsIntercept) {
        // If no intercepting reCAPTCHA is present, add the direct XHR listener
        const xhrSubmitHandler = function(e) {
            e.preventDefault();
            submitFormViaXHR(form);
        };

        // Remove potentially existing listener before adding
        // This is tricky without storing the handler reference.
        // Let's assume for now this setup runs only once initially or rely on re-init logic.
        console.log('Attaching direct XHR listener for form:', formId);
        form.addEventListener('submit', xhrSubmitHandler);

        // Store reference for potential removal later if needed (advanced)
        // form.dataset.xhrSubmitHandlerRef = xhrSubmitHandler;
    } else {
        console.log('XHR listener deferred: reCAPTCHA will intercept submit for form:', formId);
        // reCAPTCHA's submit handler will eventually call form.submit() or form.requestSubmit()
        // Since XHR is enabled (data-xhr-enabled="true"), we might need a way for the
        // reCAPTCHA callback to trigger the XHR submission explicitly instead of native submit.

        // --> Let's refine this: The reCAPTCHA script *should* call submitFormViaXHR directly
        // if data-xhr-enabled is true. Update captcha.html.twig accordingly.
    }
}

// Global namespace for XHR submitters (might be useful)
window.GravFormXHRSubmitters = window.GravFormXHRSubmitters || {};
window.GravFormXHRSubmitters.submit = submitFormViaXHR; // Expose the core function

// Initial setup function called from Twig layout
function attachFormSubmitListener(formId) {
    setupXHRListener(formId);
}
