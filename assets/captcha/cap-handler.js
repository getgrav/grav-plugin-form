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
     * Cap tokens are good for 20 minutes on the server (cap-php's default
     * token TTL) and each one works once. Treat a token as stale well before
     * that, so a page left open for a while solves again instead of posting
     * a token the server has already dropped.
     */
    const TOKEN_MAX_AGE_MS = 15 * 60 * 1000;

    /**
     * Wire up a single invisible-mode Cap container:
     *   - starts a speculative background solve
     *   - intercepts the enclosing form's submit and solves again first when
     *     the token is missing, spent or too old
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
        // Invisible mode: Cap appends its own hidden <cap-widget> to <html>.
        fillTroubleshootLink(cap.widget);
        let solvePromise = null; // the solve in flight, null when idle
        let solvedAt = 0;
        let spentToken = '';     // the token the last submit carried
        let waiting = false;     // a submit is held until a solve finishes
        let passThrough = false; // the submit we re-dispatch after that solve

        const hasFreshToken = () => tokenInput.value !== ''
            && tokenInput.value !== spentToken
            && Date.now() - solvedAt < TOKEN_MAX_AGE_MS;

        // Solve for a new token, sharing the solve in flight if there is one.
        const solve = () => {
            if (solvePromise) return solvePromise;
            tokenInput.value = '';
            solvePromise = Promise.resolve(cap.solve())
                .then((r) => {
                    if (!r || !r.token) throw new Error('no token returned');
                    tokenInput.value = r.token;
                    solvedAt = Date.now();
                    return r;
                })
                .catch((err) => { console.error('[cap] solve failed', err); throw err; })
                .finally(() => { solvePromise = null; });
            return solvePromise;
        };
        solve().catch(() => {});

        // Back on a page that sat open: start the new solve as soon as the
        // visitor touches the form, so the submit doesn't have to wait for it.
        form.addEventListener('focusin', () => {
            if (!solvePromise && !hasFreshToken()) solve().catch(() => {});
        });

        container.__capReset = () => {
            try { cap.reset(); } catch (e) { /* ignore */ }
            solve().catch(() => {});
        };

        form.addEventListener('submit', async (event) => {
            if (passThrough || hasFreshToken()) {
                passThrough = false;
                spentToken = tokenInput.value;
                return;
            }
            event.preventDefault();
            event.stopImmediatePropagation();
            if (waiting) return; // a second click while the solve runs
            waiting = true;
            const submitter = event.submitter || null;
            try {
                await solve();
            } catch (e) {
                // No token: send the form anyway, so the visitor gets the
                // server's captcha error instead of a button that does nothing.
            }
            waiting = false;
            passThrough = true;
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

    /**
     * Cap renders its troubleshooting link with no href until a failed solve
     * reveals it, and SEO audits flag the empty anchor as an uncrawlable link
     * (Lighthouse "Links are not crawlable"). Give it the URL Cap would use
     * up front; Cap still sets the href itself when it shows the link.
     */
    const TROUBLESHOOT_URL = 'https://trycap.dev/guide/troubleshooting/instrumentation.html';

    function fillTroubleshootLink(widget) {
        const link = widget && widget.shadowRoot && widget.shadowRoot.querySelector('.cap-troubleshoot-link:not([href])');
        if (link) link.setAttribute('href', widget.getAttribute('data-cap-troubleshooting-url') || TROUBLESHOOT_URL);
    }

    function fillTroubleshootLinks(root) {
        if (!window.customElements) return;
        customElements.whenDefined('cap-widget').then(() => {
            (root || document).querySelectorAll('cap-widget').forEach(fillTroubleshootLink);
        });
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
                fillTroubleshootLinks(capContainer);
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
        fillTroubleshootLinks(document);
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
