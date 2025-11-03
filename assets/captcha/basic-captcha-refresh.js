(function() {
    'use strict';

    // Function to refresh a captcha image
    const refreshCaptchaImage = function(container) {
        const img = container.querySelector('img');
        if (!img) {
            console.warn('Cannot find captcha image in container');
            return;
        }

        // Get the base URL and field ID
        const baseUrl = img.dataset.baseUrl || img.src.split('?')[0];
        const fieldId = img.dataset.fieldId || container.dataset.fieldId;

        // Force reload by adding/updating timestamp and field ID
        const timestamp = new Date().getTime();
        let newUrl = baseUrl + '?t=' + timestamp;
        if (fieldId) {
            newUrl += '&field=' + fieldId;
        }
        img.src = newUrl;

        // Also clear the input field if we can find it
        const formField = container.closest('.form-field');
        if (formField) {
            const input = formField.querySelector('input[type="text"]');
            if (input) {
                input.value = '';
                // Try to focus the input
                try { input.focus(); } catch(e) {}
            }
        }
    };

    // Function to set up click handlers for refresh buttons
    const setupRefreshButtons = function() {
        // Find all captcha containers
        const containers = document.querySelectorAll('[data-captcha-provider="basic-captcha"]');

        containers.forEach(function(container) {
            // Find the refresh button within this container
            const button = container.querySelector('button');
            if (!button) {
                return;
            }

            // Remove any existing listeners (just in case)
            button.removeEventListener('click', handleRefreshClick);

            // Add the click handler
            button.addEventListener('click', handleRefreshClick);
        });
    };

    // Click handler function
    const handleRefreshClick = function(event) {
        // Prevent default behavior and stop propagation
        event.preventDefault();
        event.stopPropagation();

        // Find the container
        const container = this.closest('[data-captcha-provider="basic-captcha"]');
        if (!container) {
            return false;
        }

        // Refresh the image
        refreshCaptchaImage(container);

        return false;
    };

    // Set up a mutation observer to handle dynamically added captchas
    const setupMutationObserver = function() {
        // Check if MutationObserver is available
        if (typeof MutationObserver === 'undefined') return;

        // Create a mutation observer to watch for new captcha elements
        const observer = new MutationObserver(function(mutations) {
            let needsSetup = false;

            mutations.forEach(function(mutation) {
                if (mutation.type === 'childList' && mutation.addedNodes.length) {
                    // Check if any of the added nodes contain our captcha containers
                    for (let i = 0; i < mutation.addedNodes.length; i++) {
                        const node = mutation.addedNodes[i];
                        if (node.nodeType === Node.ELEMENT_NODE) {
                            // Check if this element has or contains captcha containers
                            if (node.querySelector && (
                                node.matches('[data-captcha-provider="basic-captcha"]') ||
                                node.querySelector('[data-captcha-provider="basic-captcha"]')
                            )) {
                                needsSetup = true;
                                break;
                            }
                        }
                    }
                }
            });

            if (needsSetup) {
                setupRefreshButtons();
            }
        });

        // Start observing the document
        observer.observe(document.body, {
            childList: true,
            subtree: true
        });
    };

    // Initialize on DOM ready
    document.addEventListener('DOMContentLoaded', function() {
        setupRefreshButtons();
        setupMutationObserver();

        // Also connect to XHR system if available (for best of both worlds)
        if (window.GravFormXHR && window.GravFormXHR.captcha) {
            window.GravFormXHR.captcha.register('basic-captcha', {
                reset: function(container, form) {
                    refreshCaptchaImage(container);
                }
            });
        }
    });
})();