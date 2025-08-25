/**
 * FilePond Direct Fix - Emergency fix for XHR forms
 */
(function() {
    // Directly attempt to initialize uninitialized FilePond elements
    // without relying on any existing logic

    console.log('FilePond Direct Fix loaded');

    // Function to directly create FilePond instances
    function initializeFilePondElements() {
        console.log('Direct FilePond initialization attempt');

        // Find uninitialized FilePond elements
        const elements = document.querySelectorAll('.filepond-root:not(.filepond--hopper)');
        if (elements.length === 0) {
            return;
        }

        console.log(`Found ${elements.length} uninitialized FilePond elements`);

        // Process each element
        elements.forEach((element, index) => {
            const input = element.querySelector('input[type="file"]:not(.filepond--browser)');
            if (!input) {
                console.log(`Element #${index + 1}: No suitable file input found`);
                return;
            }

            console.log(`Element #${index + 1}: Found file input:`, input);

            // Get settings
            let settings = {};
            try {
                const settingsAttr = element.getAttribute('data-grav-file-settings');
                if (settingsAttr) {
                    settings = JSON.parse(settingsAttr);
                    console.log('Parsed settings:', settings);
                }
            } catch (e) {
                console.error('Failed to parse settings:', e);
            }

            // Get URLS
            const uploadUrl = element.getAttribute('data-file-url-add');
            const removeUrl = element.getAttribute('data-file-url-remove');

            console.log('Upload URL:', uploadUrl);
            console.log('Remove URL:', removeUrl);

            try {
                // Create FilePond instance directly
                const pond = FilePond.create(input);

                // Apply minimal configuration to make uploads work
                if (pond) {
                    console.log(`Successfully created FilePond on element #${index + 1}`);

                    // Basic configuration to make it functional
                    pond.setOptions({
                        name: settings.paramName || input.name || 'files',
                        server: {
                            process: uploadUrl,
                            revert: removeUrl
                        },
                        // Transform options
                        imageTransformOutputMimeType: 'image/jpeg',
                        imageTransformOutputQuality: settings.resizeQuality || 90,
                        imageTransformOutputStripImageHead: true,
                        // Resize options
                        imageResizeTargetWidth: settings.resizeWidth || null,
                        imageResizeTargetHeight: settings.resizeHeight || null,
                        imageResizeMode: 'cover',
                        imageResizeUpscale: false
                    });
                }
            } catch (e) {
                console.error(`Failed to create FilePond on element #${index + 1}:`, e);
            }
        });
    }

    // Monitor form submissions and DOM changes
    function setupMonitoring() {
        // Create MutationObserver to watch for DOM changes
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
                    console.log('DOM changes detected that might include FilePond elements');
                    // Delay to ensure DOM is fully updated
                    setTimeout(initializeFilePondElements, 50);
                }
            });

            // Start observing
            observer.observe(document.body, {
                childList: true,
                subtree: true
            });

            console.log('MutationObserver set up for FilePond elements');
        }
    }

    // Set up the emergency fix
    function init() {
        // Set up monitoring
        setupMonitoring();

        // Expose global function for manual reinit
        window.directFilePondInit = initializeFilePondElements;

        // Initial check
        setTimeout(initializeFilePondElements, 500);
    }

    // Start when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        setTimeout(init, 0);
    }
})();
