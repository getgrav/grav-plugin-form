/**
 * Unified Grav Form FilePond Handler
 *
 * This script initializes and configures FilePond instances for file uploads
 * within Grav forms. It works with both normal and XHR form submissions.
 * It also handles reinitializing FilePond instances after XHR form submissions.
 */

// Immediately-Invoked Function Expression for scoping
(function () {
    // Check if script already loaded
    if (window.gravFilepondHandlerLoaded) {
        console.log('FilePond unified handler already loaded, skipping.');
        return;
    }
    window.gravFilepondHandlerLoaded = true;

    // Debugging - set to false for production
    const debug = true;

    // Helper function for logging
    function log(message, type = 'log') {
        if (!debug && type !== 'error') return;

        const prefix = '[FilePond Handler]';
        if (type === 'error') {
            console.error(prefix, message);
        } else if (type === 'warn') {
            console.warn(prefix, message);
        } else {
            console.log(prefix, message);
        }
    }

    // Track FilePond instances with their configuration
    const pondInstances = new Map();

    // Get translations from global object if available
    const translations = window.GravForm?.translations?.PLUGIN_FORM || {
        FILEPOND_REMOVE_FILE: 'Remove file',
        FILEPOND_REMOVE_FILE_CONFIRMATION: 'Are you sure you want to remove this file?',
        FILEPOND_CANCEL_UPLOAD: 'Cancel upload',
        FILEPOND_ERROR_FILESIZE: 'File is too large',
        FILEPOND_ERROR_FILETYPE: 'Invalid file type'
    };

    // Track initialization state
    let initialized = false;

    /**
     * Get standard FilePond configuration for an element
     * This is used for both initial setup and reinit after XHR
     * @param {HTMLElement} element - The file input element
     * @param {HTMLElement} container - The container element
     * @returns {Object} Configuration object for FilePond
     */
    function getFilepondConfig(element, container) {
        if (!container) {
            log('Container not provided for config extraction', 'error');
            return null;
        }

        // Check if the field is required - this is correct location
        const isRequired = element.hasAttribute('required') ||
            container.hasAttribute('required') ||
            container.getAttribute('data-required') === 'true';

        // Then, add this code to remove the required attribute from the actual input
        // to prevent browser validation errors, but keep track of the requirement
        if (isRequired) {
            // Store the required state on the container for our custom validation
            container.setAttribute('data-required', 'true');
            // Remove the required attribute from the input to avoid browser validation errors
            element.removeAttribute('required');
        }

        try {
            // Get settings from data attributes
            const settingsAttr = container.getAttribute('data-grav-file-settings');
            if (!settingsAttr) {
                log('No file settings found for FilePond element', 'warn');
                return null;
            }

            // Parse settings
            let settings;
            try {
                settings = JSON.parse(settingsAttr);
                log('Parsed settings:', settings);
            } catch (e) {
                log(`Error parsing file settings: ${e.message}`, 'error');
                return null;
            }

            // Parse FilePond options
            const filepondOptionsAttr = container.getAttribute('data-filepond-options') || '{}';
            let filepondOptions;
            try {
                filepondOptions = JSON.parse(filepondOptionsAttr);
                log('Parsed FilePond options:', filepondOptions);
            } catch (e) {
                log(`Error parsing FilePond options: ${e.message}`, 'error');
                filepondOptions = {};
            }

            // Get URLs for upload and remove
            const uploadUrl = container.getAttribute('data-file-url-add');
            const removeUrl = container.getAttribute('data-file-url-remove');

            if (!uploadUrl) {
                log('Upload URL not found for FilePond element', 'warn');
                return null;
            }

            // Parse previously uploaded files
            const existingFiles = [];
            const fileDataElements = container.querySelectorAll('[data-file]');
            log(`Found ${fileDataElements.length} existing file data elements`);

            fileDataElements.forEach(fileData => {
                try {
                    const fileAttr = fileData.getAttribute('data-file');
                    log('File data attribute:', fileAttr);

                    const fileJson = JSON.parse(fileAttr);

                    if (fileJson && fileJson.name) {
                        existingFiles.push({
                            source: fileJson.name,
                            options: {
                                type: 'local',
                                file: {
                                    name: fileJson.name,
                                    size: fileJson.size,
                                    type: fileJson.type
                                },
                                metadata: {
                                    poster: fileJson.thumb_url || fileJson.path
                                }
                            }
                        });
                    }
                } catch (e) {
                    log(`Error parsing file data: ${e.message}`, 'error');
                }
            });

            log('Existing files:', existingFiles);

            // Get form elements for Grav integration
            const fieldName = container.getAttribute('data-file-field-name');
            const form = element.closest('form');
            const formNameInput = form ? form.querySelector('[name="__form-name__"]') : document.querySelector('[name="__form-name__"]');
            const formIdInput = form ? form.querySelector('[name="__unique_form_id__"]') : document.querySelector('[name="__unique_form_id__"]');
            const formNonceInput = form ? form.querySelector('[name="form-nonce"]') : document.querySelector('[name="form-nonce"]');

            if (!formNameInput || !formIdInput || !formNonceInput) {
                log('Missing required form inputs for proper Grav integration', 'warn');
            }

            // Configure FilePond
            const options = {
                // Core settings
                name: settings.paramName,
                maxFiles: settings.limit || null,
                maxFileSize: `${settings.filesize}MB`,
                acceptedFileTypes: settings.accept,
                files: existingFiles,

                // Server configuration - modified for Grav
                server: {
                    process: {
                        url: uploadUrl,
                        method: 'POST',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        ondata: (formData) => {
                            // Safety check - ensure formData is valid
                            if (!formData) {
                                console.error('FormData is undefined in ondata');
                                return new FormData(); // Return empty FormData as fallback
                            }

                            // Add all required Grav form fields
                            if (formNameInput) formData.append('__form-name__', formNameInput.value);
                            if (formIdInput) formData.append('__unique_form_id__', formIdInput.value);
                            formData.append('__form-file-uploader__', '1');
                            if (formNonceInput) formData.append('form-nonce', formNonceInput.value);
                            formData.append('task', 'filesupload');

                            // Use fieldName from the outer scope
                            if (fieldName) {
                                formData.append('name', fieldName);
                            } else {
                                console.error('Field name is undefined, falling back to default');
                                formData.append('name', 'files');
                            }

                            // Add URI if needed
                            const uriInput = document.querySelector('[name="uri"]');
                            if (uriInput) {
                                formData.append('uri', uriInput.value);
                            }

                            // Note: Don't try to append file here, FilePond will do that based on the name parameter
                            // Just return the modified formData
                            log('Prepared form data for Grav upload');
                            return formData;
                        }
                    },
                    revert: removeUrl ? {
                        url: removeUrl,
                        method: 'POST',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        ondata: (formData, file) => {
                            // Add all required Grav form fields
                            if (formNameInput) formData.append('__form-name__', formNameInput.value);
                            if (formIdInput) formData.append('__unique_form_id__', formIdInput.value);
                            formData.append('__form-file-remover__', '1');
                            if (formNonceInput) formData.append('form-nonce', formNonceInput.value);
                            formData.append('name', fieldName);

                            // Add filename
                            formData.append('filename', file.filename);

                            log('Prepared form data for file removal');
                            return formData;
                        }
                    } : null
                },

                // Image Transform settings - both FilePond native settings and our custom ones
                // Native settings
                allowImagePreview: true,
                allowImageResize: true,
                allowImageTransform: true,
                imagePreviewHeight: filepondOptions.imagePreviewHeight || 256,

                // Transform settings
                imageTransformOutputMimeType: filepondOptions.imageTransformOutputMimeType || 'image/jpeg',
                imageTransformOutputQuality: filepondOptions.imageTransformOutputQuality || settings.resizeQuality || 90,
                imageTransformOutputStripImageHead: filepondOptions.imageTransformOutputStripImageHead !== false,

                // Resize settings
                imageResizeTargetWidth: filepondOptions.imageResizeTargetWidth || settings.resizeWidth || null,
                imageResizeTargetHeight: filepondOptions.imageResizeTargetHeight || settings.resizeHeight || null,
                imageResizeMode: filepondOptions.imageResizeMode || 'cover',
                imageResizeUpscale: filepondOptions.imageResizeUpscale || false,

                // Crop settings
                allowImageCrop: filepondOptions.allowImageCrop || false,
                imageCropAspectRatio: filepondOptions.imageCropAspectRatio || null,

                // Labels and translations
                labelIdle: filepondOptions.labelIdle || '<span class="filepond--label-action">Browse</span> or drop files',
                labelFileTypeNotAllowed: translations.FILEPOND_ERROR_FILETYPE || 'Invalid file type',
                labelFileSizeNotAllowed: translations.FILEPOND_ERROR_FILESIZE || 'File is too large',
                labelFileLoading: 'Loading',
                labelFileProcessing: 'Uploading',
                labelFileProcessingComplete: 'Upload complete',
                labelFileProcessingAborted: 'Upload cancelled',
                labelTapToCancel: translations.FILEPOND_CANCEL_UPLOAD || 'Cancel upload',
                labelTapToRetry: 'Retry',
                labelTapToUndo: 'Undo',
                labelButtonRemoveItem: translations.FILEPOND_REMOVE_FILE || 'Remove',

                // Style settings
                stylePanelLayout: filepondOptions.stylePanelLayout || 'compact',
                styleLoadIndicatorPosition: filepondOptions.styleLoadIndicatorPosition || 'center bottom',
                styleProgressIndicatorPosition: filepondOptions.styleProgressIndicatorPosition || 'center bottom',
                styleButtonRemoveItemPosition: filepondOptions.styleButtonRemoveItemPosition || 'right',

                // Override with any remaining user-provided options
                ...filepondOptions
            };

            log('Prepared FilePond configuration:', options);

            return options;
        } catch (e) {
            log(`Error creating FilePond configuration: ${e.message}`, 'error');
            console.error(e); // Full error in console
            return null;
        }
    }

    /**
     * Initialize a single FilePond instance
     * @param {HTMLElement} element - The file input element to initialize
     * @returns {FilePond|null} The created FilePond instance, or null if creation failed
     */
    function initializeSingleFilePond(element) {
        const container = element.closest('.filepond-root');

        if (!container) {
            log('FilePond container not found for input element', 'error');
            return null;
        }

        // Don't initialize twice
        if (container.classList.contains('filepond--hopper') || container.querySelector('.filepond--hopper')) {
            log('FilePond already initialized for this element, skipping');
            return null;
        }

        // Get the element ID or create a unique one for tracking
        const elementId = element.id || `filepond-${Math.random().toString(36).substring(2, 15)}`;

        // Get configuration
        const config = getFilepondConfig(element, container);
        if (!config) {
            log('Failed to get configuration, cannot initialize FilePond', 'error');
            return null;
        }

        log(`Initializing FilePond element ${elementId} with config`, config);

        try {
            // Create FilePond instance
            const pond = FilePond.create(element, config);
            log(`FilePond instance created successfully for element ${elementId}`);

            // Store the instance and its configuration for potential reinit
            pondInstances.set(elementId, {
                instance: pond,
                config: config,
                container: container
            });

            // Add a reference to the element for easier lookup
            element.filepondId = elementId;
            container.filepondId = elementId;

            // Handle form submission to ensure files are processed before submit
            const form = element.closest('form');
            if (form && !form._filepond_handler_attached) {
                form._filepond_handler_attached = true;

                form.addEventListener('submit', function (e) {
                    // Check for all FilePond instances in this form
                    const formPonds = Array.from(pondInstances.values())
                        .filter(info => info.instance && info.container.closest('form') === form);

                    const processingFiles = formPonds.reduce((total, info) => {
                        return total + info.instance.getFiles().filter(file =>
                            file.status === FilePond.FileStatus.PROCESSING_QUEUED ||
                            file.status === FilePond.FileStatus.PROCESSING
                        ).length;
                    }, 0);

                    if (processingFiles > 0) {
                        e.preventDefault();
                        alert('Please wait for all files to finish uploading before submitting the form.');
                        return false;
                    }
                });
            }

            return pond;
        } catch (e) {
            log(`Error creating FilePond instance: ${e.message}`, 'error');
            console.error(e); // Full error in console
            return null;
        }
    }

    /**
     * Main FilePond initialization function
     * This will find and initialize all uninitialized FilePond elements
     */
    function initializeFilePond() {
        log('Starting FilePond initialization');

        // Make sure we have the libraries loaded
        if (typeof window.FilePond === 'undefined') {
            log('FilePond library not found. Will retry in 500ms...', 'warn');
            setTimeout(initializeFilePond, 500);
            return;
        }

        log('FilePond library found, continuing initialization');

        // Register plugins if available
        try {
            if (window.FilePondPluginFileValidateSize) {
                FilePond.registerPlugin(FilePondPluginFileValidateSize);
                log('Registered FileValidateSize plugin');
            }

            if (window.FilePondPluginFileValidateType) {
                FilePond.registerPlugin(FilePondPluginFileValidateType);
                log('Registered FileValidateType plugin');
            }

            if (window.FilePondPluginImagePreview) {
                FilePond.registerPlugin(FilePondPluginImagePreview);
                log('Registered ImagePreview plugin');
            }

            if (window.FilePondPluginImageResize) {
                FilePond.registerPlugin(FilePondPluginImageResize);
                log('Registered ImageResize plugin');
            }

            if (window.FilePondPluginImageTransform) {
                FilePond.registerPlugin(FilePondPluginImageTransform);
                log('Registered ImageTransform plugin');
            }
        } catch (e) {
            log(`Error registering plugins: ${e.message}`, 'error');
        }

        // Find all FilePond elements
        const elements = document.querySelectorAll('.filepond-root input[type="file"]:not(.filepond--browser)');

        if (elements.length === 0) {
            log('No FilePond form elements found on the page');
            return;
        }

        log(`Found ${elements.length} FilePond element(s)`);

        // Process each FilePond element
        elements.forEach((element, index) => {
            log(`Initializing FilePond element #${index + 1}`);
            initializeSingleFilePond(element);
        });

        initialized = true;
        log('FilePond initialization complete');
    }

    /**
     * Reinitialize a specific FilePond instance
     * @param {HTMLElement} container - The FilePond container element
     * @returns {FilePond|null} The reinitialized FilePond instance, or null if reinitialization failed
     */
    function reinitializeSingleFilePond(container) {
        if (!container) {
            log('No container provided for reinitialization', 'error');
            return null;
        }

        // Check if this is a FilePond container
        if (!container.classList.contains('filepond-root')) {
            log('Container is not a FilePond container', 'warn');
            return null;
        }

        log(`Reinitializing FilePond container: ${container.id || 'unnamed'}`);

        // If already initialized, destroy first
        if (container.classList.contains('filepond--hopper') || container.querySelector('.filepond--hopper')) {
            log('Container already has an active FilePond instance, destroying it first');

            // Try to find and destroy through our internal tracking
            const elementId = container.filepondId;
            if (elementId && pondInstances.has(elementId)) {
                const info = pondInstances.get(elementId);
                if (info.instance) {
                    log(`Destroying tracked FilePond instance for element ${elementId}`);
                    info.instance.destroy();
                    pondInstances.delete(elementId);
                }
            } else {
                // Fallback: Try to find via child element with class
                const pondElement = container.querySelector('.filepond--root');
                if (pondElement && pondElement._pond) {
                    log('Destroying FilePond instance via DOM reference');
                    pondElement._pond.destroy();
                }
            }
        }

        // Look for the file input
        const input = container.querySelector('input[type="file"]:not(.filepond--browser)');
        if (!input) {
            log('No file input found in container for reinitialization', 'error');
            return null;
        }

        // Create a new instance
        return initializeSingleFilePond(input);
    }

    /**
     * Reinitialize all FilePond instances
     * This is used after XHR form submissions
     */
    function reinitializeFilePond() {
        log('Reinitializing all FilePond instances');

        // Find all FilePond containers
        const containers = document.querySelectorAll('.filepond-root');
        if (containers.length === 0) {
            log('No FilePond containers found for reinitialization');
            return;
        }

        log(`Found ${containers.length} FilePond container(s) for reinitialization`);

        // Process each container
        containers.forEach((container, index) => {
            log(`Reinitializing FilePond container #${index + 1}`);
            reinitializeSingleFilePond(container);
        });

        log('FilePond reinitialization complete');
    }

    /**
     * Helper function to support XHR form interaction
     * This hooks into the GravFormXHR system if available
     */
    function setupXHRIntegration() {
        // Only run if GravFormXHR is available
        if (window.GravFormXHR) {
            log('Setting up XHR integration for FilePond');

            // Store original submit function
            const originalSubmit = window.GravFormXHR.submit;

            // Override to handle FilePond files
            window.GravFormXHR.submit = function (form) {
                if (!form) {
                    return originalSubmit.apply(this, arguments);
                }

                // Check for any FilePond instances in the form
                let hasPendingUploads = false;

                // First check via our tracking
                Array.from(pondInstances.values()).forEach(info => {
                    if (info.container.closest('form') === form) {
                        const processingFiles = info.instance.getFiles().filter(file =>
                            file.status === FilePond.FileStatus.PROCESSING_QUEUED ||
                            file.status === FilePond.FileStatus.PROCESSING);

                        if (processingFiles.length > 0) {
                            hasPendingUploads = true;
                        }
                    }
                });

                // Fallback check for any untracked instances
                if (!hasPendingUploads) {
                    const filepondContainers = form.querySelectorAll('.filepond-root');
                    filepondContainers.forEach(container => {
                        const pondElement = container.querySelector('.filepond--root');
                        if (pondElement && pondElement._pond) {
                            const pond = pondElement._pond;
                            const processingFiles = pond.getFiles().filter(file =>
                                file.status === FilePond.FileStatus.PROCESSING_QUEUED ||
                                file.status === FilePond.FileStatus.PROCESSING);

                            if (processingFiles.length > 0) {
                                hasPendingUploads = true;
                            }
                        }
                    });
                }

                if (hasPendingUploads) {
                    alert('Please wait for all files to finish uploading before submitting the form.');
                    return false;
                }

                // Call the original submit function
                return originalSubmit.apply(this, arguments);
            };

            // Set up listeners for form updates
            document.addEventListener('grav-form-updated', function (e) {
                log('Detected form update event, reinitializing FilePond instances');
                setTimeout(reinitializeFilePond, 100);
            });
        }
    }

    /**
     * Setup mutation observer to detect dynamically added FilePond elements
     */
    function setupMutationObserver() {
        if (window.MutationObserver) {
            const observer = new MutationObserver((mutations) => {
                let shouldCheck = false;

                for (const mutation of mutations) {
                    if (mutation.type === 'childList' && mutation.addedNodes.length) {
                        for (const node of mutation.addedNodes) {
                            if (node.nodeType === 1) {
                                if (node.classList && node.classList.contains('filepond-root') ||
                                    node.querySelector && node.querySelector('.filepond-root')) {
                                    shouldCheck = true;
                                    break;
                                }
                            }
                        }
                    }

                    if (shouldCheck) break;
                }

                if (shouldCheck) {
                    log('DOM changes detected that might include FilePond elements');
                    // Delay to ensure DOM is fully updated
                    setTimeout(initializeFilePond, 50);
                }
            });

            // Start observing
            observer.observe(document.body, {
                childList: true,
                subtree: true
            });

            log('MutationObserver set up for FilePond elements');
        }
    }

    /**
     * Initialize when DOM is ready
     */
    function domReadyInit() {
        log('DOM ready, initializing FilePond');
        initializeFilePond();
        setupXHRIntegration();
        setupMutationObserver();
    }

    // Handle different document ready states
    if (document.readyState === 'loading') {
        log('Document still loading, adding DOMContentLoaded listener');
        document.addEventListener('DOMContentLoaded', domReadyInit);
    } else {
        log('Document already loaded, initializing now');
        setTimeout(domReadyInit, 0);
    }

    // Also support initialization via window load event as a fallback
    window.addEventListener('load', function () {
        log('Window load event fired');
        if (!initialized) {
            log('FilePond not yet initialized, initializing now');
            initializeFilePond();
        }
    });

    // Expose functions to global scope for external usage
    window.GravFilePond = {
        initialize: initializeFilePond,
        reinitialize: reinitializeFilePond,
        reinitializeContainer: reinitializeSingleFilePond,
        getInstances: () => Array.from(pondInstances.values()).map(info => info.instance)
    };

    // Log initialization start
    log('FilePond unified handler script loaded and ready');
})();