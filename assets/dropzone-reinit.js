/**
 * Direct Dropzone Initialization for XHR Forms
 *
 * This script directly targets Form plugin's Dropzone initialization mechanisms
 */
(function() {
    'use strict';

    // Enable debugging logs
    const DEBUG = false;

    // Helper function for logging
    function log(message, type = 'log') {
        if (!DEBUG) return;

        const prefix = '[Dropzone Direct Init]';

        if (type === 'error') {
            console.error(prefix, message);
        } else if (type === 'warn') {
            console.warn(prefix, message);
        } else {
            console.log(prefix, message);
        }
    }

    // Flag to prevent multiple initializations
    let isInitializing = false;

    // Function to directly initialize Dropzone
    function initializeDropzone(element) {
        if (isInitializing) {
            log('Initialization already in progress, skipping');
            return false;
        }

        if (!element || element.classList.contains('dz-clickable')) {
            return false;
        }

        log('Starting direct Dropzone initialization for element:', element);
        isInitializing = true;

        // First, let's try to find the FilesField constructor in the global scope
        if (typeof FilesField === 'function') {
            log('Found FilesField constructor, trying direct instantiation');

            try {
                new FilesField({
                    container: element,
                    options: {}
                });

                log('Successfully initialized Dropzone using FilesField constructor');
                isInitializing = false;
                return true;
            } catch (e) {
                log(`Error using FilesField constructor: ${e.message}`, 'error');
                // Continue with other methods
            }
        }

        // Second approach: Look for the Form plugin's initialization code in the page
        const dropzoneInit = findFunctionOnWindow('addNode') ||
                            window.addNode ||
                            findFunctionOnWindow('initDropzone');

        if (dropzoneInit) {
            log('Found Form plugin initialization function, calling it directly');

            try {
                dropzoneInit(element);
                log('Successfully called Form plugin initialization function');
                isInitializing = false;
                return true;
            } catch (e) {
                log(`Error calling Form plugin initialization function: ${e.message}`, 'error');
                // Continue with other methods
            }
        }

        // Third approach: Try to invoke Dropzone directly if it's globally available
        if (typeof Dropzone === 'function') {
            log('Found global Dropzone constructor, trying direct instantiation');

            try {
                // Extract settings from the element
                const settingsAttr = element.getAttribute('data-grav-file-settings');
                if (!settingsAttr) {
                    log('No settings found for element', 'warn');
                    isInitializing = false;
                    return false;
                }

                const settings = JSON.parse(settingsAttr);
                const optionsAttr = element.getAttribute('data-dropzone-options');
                const options = optionsAttr ? JSON.parse(optionsAttr) : {};

                // Configure Dropzone options
                const dropzoneOptions = {
                    url: element.getAttribute('data-file-url-add') || window.location.href,
                    maxFiles: settings.limit || null,
                    maxFilesize: settings.filesize || 10,
                    acceptedFiles: settings.accept ? settings.accept.join(',') : null
                };

                // Merge with any provided options
                Object.assign(dropzoneOptions, options);

                // Create new Dropzone instance
                new Dropzone(element, dropzoneOptions);

                log('Successfully initialized Dropzone using global constructor');
                isInitializing = false;
                return true;
            } catch (e) {
                log(`Error using global Dropzone constructor: ${e.message}`, 'error');
                // Continue to final approach
            }
        }

        // Final approach: Force reloading of Form plugin's JavaScript
        log('Attempting to force reload Form plugin JavaScript');

        // Look for Form plugin's JS files
        const formVendorScript = document.querySelector('script[src*="form.vendor.js"]');
        const formScript = document.querySelector('script[src*="form.min.js"]');

        if (formVendorScript || formScript) {
            log('Found Form plugin scripts, attempting to reload them');

            // Create new script elements
            if (formVendorScript) {
                const newVendorScript = document.createElement('script');
                newVendorScript.src = formVendorScript.src.split('?')[0] + '?t=' + new Date().getTime();
                newVendorScript.async = true;
                newVendorScript.onload = function() {
                    log('Reloaded Form vendor script');

                    // Trigger event after script loads
                    setTimeout(function() {
                        const event = new CustomEvent('mutation._grav', {
                            detail: { target: element }
                        });
                        document.body.dispatchEvent(event);
                    }, 100);
                };
                document.head.appendChild(newVendorScript);
            }

            if (formScript) {
                const newFormScript = document.createElement('script');
                newFormScript.src = formScript.src.split('?')[0] + '?t=' + new Date().getTime();
                newFormScript.async = true;
                newFormScript.onload = function() {
                    log('Reloaded Form script');

                    // Trigger event after script loads
                    setTimeout(function() {
                        const event = new CustomEvent('mutation._grav', {
                            detail: { target: element }
                        });
                        document.body.dispatchEvent(event);
                    }, 100);
                };
                document.head.appendChild(newFormScript);
            }
        }

        // As a final resort, trigger the mutation event
        log('Triggering mutation._grav event as final resort');
        const event = new CustomEvent('mutation._grav', {
            detail: { target: element }
        });
        document.body.dispatchEvent(event);

        isInitializing = false;
        return false;
    }

    // Helper function to find a function on the window object by name pattern
    function findFunctionOnWindow(pattern) {
        for (const key in window) {
            if (typeof window[key] === 'function' && key.includes(pattern)) {
                return window[key];
            }
        }
        return null;
    }

    // Function to check all Dropzone elements
    function checkAllDropzones() {
        const dropzones = document.querySelectorAll('.dropzone.files-upload:not(.dz-clickable)');

        if (dropzones.length === 0) {
            log('No uninitialized Dropzone elements found');
            return;
        }

        log(`Found ${dropzones.length} uninitialized Dropzone elements`);

        // Try to initialize each one
        dropzones.forEach(function(element) {
            initializeDropzone(element);
        });
    }

    // Hook into form submission to reinitialize after XHR updates
    function setupFormSubmissionHook() {
        // First check if the XHR submit function is available
        if (window.GravFormXHR && typeof window.GravFormXHR.submit === 'function') {
            log('Found GravFormXHR.submit, attaching hook');

            // Store the original function
            const originalSubmit = window.GravFormXHR.submit;

            // Override it with our version
            window.GravFormXHR.submit = function(form) {
                log(`XHR form submission detected for form: ${form?.id || 'unknown'}`);

                // Call the original function
                const result = originalSubmit.apply(this, arguments);

                // Set up checks for after the submission completes
                [500, 1000, 2000, 3000].forEach(function(delay) {
                    setTimeout(checkAllDropzones, delay);
                });

                return result;
            };

            log('Successfully hooked into GravFormXHR.submit');
        }

        // Also add a direct event listener for standard form submissions
        document.addEventListener('submit', function(event) {
            if (event.target.tagName === 'FORM') {
                log(`Standard form submission detected for form: ${event.target.id || 'unknown'}`);

                // Schedule checks after submission
                [1000, 2000, 3000].forEach(function(delay) {
                    setTimeout(checkAllDropzones, delay);
                });
            }
        });

        log('Form submission hooks set up');
    }

    // Monitor for AJAX responses
    function setupAjaxMonitoring() {
        if (window.jQuery) {
            log('Setting up jQuery AJAX response monitoring');

            jQuery(document).ajaxComplete(function(event, xhr, settings) {
                log('AJAX request completed, checking if form-related');

                // Check if this looks like a form request
                const url = settings.url || '';
                if (url.includes('form') ||
                    url.includes('task=') ||
                    url.includes('file-upload') ||
                    url.includes('file-uploader')) {

                    log('Form-related AJAX request detected, will check for Dropzones');

                    // Schedule checks with delays
                    [300, 800, 1500].forEach(function(delay) {
                        setTimeout(checkAllDropzones, delay);
                    });
                }
            });

            log('jQuery AJAX monitoring set up');
        }
    }

    // Create global function for manual reinitialization
    window.reinitializeDropzones = function() {
        log('Manual reinitialization triggered');
        checkAllDropzones();
        return 'Reinitialization check triggered. See console for details.';
    };

    // Main initialization function
    function initialize() {
        log('Initializing Dropzone direct initialization system');

        // Set up submission hook
        setupFormSubmissionHook();

        // Set up AJAX monitoring
        setupAjaxMonitoring();

        // Do an initial check for any uninitialized Dropzones
        setTimeout(checkAllDropzones, 500);

        log('Initialization complete. Use window.reinitializeDropzones() for manual reinitialization.');
    }

    // Start when the DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            // Delay to allow other scripts to load
            setTimeout(initialize, 100);
        });
    } else {
        // DOM already loaded, delay slightly
        setTimeout(initialize, 100);
    }
})();