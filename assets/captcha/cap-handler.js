(function () {
    'use strict';

    /**
     * Set window.CAP_CUSTOM_WASM_URL from any cap container's data attribute
     * so the vendored WASM binary is used instead of the default jsDelivr CDN.
     * Safe to call multiple times; noop after first assignment.
     */
    function ensureWasmUrl(root) {
        if (window.CAP_CUSTOM_WASM_URL) return;
        const scope = root || document;
        const c = scope.querySelector('[data-captcha-provider="cap"][data-cap-wasm-url]');
        if (c) window.CAP_CUSTOM_WASM_URL = c.dataset.capWasmUrl;
    }

    /**
     * Wire up a single invisible-mode Cap container:
     *   - starts a speculative background solve
     *   - intercepts the enclosing form's submit to wait for the token
     *   - exposes __capReset / __capWired on the container so we can
     *     skip double-wiring and re-arm after an XHR submit.
     *
     * Safe to call repeatedly: returns early if the container is already wired.
     */
    function wireInvisibleContainer(container) {
        if (!container || container.__capWired) return;
        if (typeof window.Cap !== 'function') {
            // cap.min.js hasn't loaded yet — try again shortly.
            setTimeout(() => wireInvisibleContainer(container), 50);
            return;
        }

        const form = container.closest('form') || document.getElementById(container.dataset.formId);
        if (!form) return;

        const tokenInput = container.querySelector('input[name="cap-token"]');
        if (!tokenInput) return;

        const endpoint = container.dataset.capApiEndpoint || '/forms-cap/';

        container.__capWired = true;

        const cap = new window.Cap({ apiEndpoint: endpoint });
        let solvePromise = null;
        let verified = false;

        const startSolve = () => {
            verified = false;
            tokenInput.value = '';
            solvePromise = cap.solve()
                .then((r) => { tokenInput.value = r.token; return r; })
                .catch((err) => { console.error('[cap] solve failed', err); throw err; });
        };
        startSolve();

        container.__capReset = () => {
            try { cap.reset(); } catch (e) { /* ignore */ }
            startSolve();
        };

        form.addEventListener('submit', async (event) => {
            if (verified && tokenInput.value) return;
            event.preventDefault();
            event.stopImmediatePropagation();
            const submitter = event.submitter || null;
            try {
                await solvePromise;
            } catch (e) {
                return;
            }
            verified = true;
            // Defer: HTMLFormElement.requestSubmit() is a no-op while
            // the form's "firing submission events" flag is still set,
            // i.e. while we're still inside the original submit handler.
            setTimeout(() => {
                if (submitter) {
                    form.requestSubmit(submitter);
                } else {
                    form.requestSubmit();
                }
            }, 0);
        }, true);
    }

    /**
     * Scan the document (or a specific root) for any invisible Cap containers
     * that haven't been wired yet and wire them up.
     */
    function wireAllInvisible(root) {
        const scope = root || document;
        const containers = scope.querySelectorAll(
            '[data-captcha-provider="cap"][data-cap-mode="invisible"]'
        );
        containers.forEach(wireInvisibleContainer);
    }

    function registerXhrHandler() {
        if (!window.GravFormXHR || !window.GravFormXHR.captcha) return false;

        window.GravFormXHR.captcha.register('cap', {
            reset: function (container, form) {
                const capContainer = (container && container.matches('[data-captcha-provider="cap"]'))
                    ? container
                    : form.querySelector('[data-captcha-provider="cap"]');

                if (!capContainer || !capContainer.isConnected) return;

                const mode = capContainer.dataset.capMode || 'invisible';

                if (mode === 'invisible') {
                    // After an XHR form re-render, the container is usually a
                    // brand-new element — wire it up from scratch. If it's the
                    // same element we already wired, re-arm in place.
                    ensureWasmUrl(form);
                    if (capContainer.__capWired && typeof capContainer.__capReset === 'function') {
                        capContainer.__capReset();
                    } else {
                        wireInvisibleContainer(capContainer);
                    }
                    return;
                }

                // Checkbox mode: reset the <cap-widget> if it's solved.
                const widget = capContainer.querySelector('cap-widget');
                if (!widget || !widget.isConnected || !widget.token) return;
                try { widget.reset(); } catch (e) { console.error('Error resetting Cap widget:', e); }
                const tokenInput = form.querySelector('input[name="cap-token"]');
                if (tokenInput) tokenInput.value = '';
            }
        });
        return true;
    }

    function init() {
        ensureWasmUrl(document);
        wireAllInvisible(document);
        registerXhrHandler();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // Expose for manual re-wiring (e.g., when a form is dynamically inserted).
    window.GravCapCaptcha = window.GravCapCaptcha || {};
    window.GravCapCaptcha.wireAll = wireAllInvisible;
    window.GravCapCaptcha.wire = wireInvisibleContainer;
})();
