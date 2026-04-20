(function () {
    'use strict';

    const registerCapHandler = function () {
        if (!window.GravFormXHR || !window.GravFormXHR.captcha) {
            console.error('GravFormXHR.captcha not found. Make sure the Form plugin is loaded correctly.');
            return;
        }

        window.GravFormXHR.captcha.register('cap', {
            reset: function (container, form) {
                const capContainer = (container && container.matches('[data-captcha-provider="cap"]'))
                    ? container
                    : form.querySelector('[data-captcha-provider="cap"]');

                if (!capContainer || !capContainer.isConnected) {
                    return;
                }

                const mode = capContainer.dataset.capMode || 'invisible';

                if (mode === 'invisible') {
                    if (typeof capContainer.__capReset === 'function') {
                        capContainer.__capReset();
                    }
                    return;
                }

                // Checkbox mode: reset the <cap-widget> element if it's solved.
                const widget = capContainer.querySelector('cap-widget');
                if (!widget || !widget.isConnected || !widget.token) {
                    return;
                }

                try {
                    widget.reset();
                } catch (e) {
                    console.error('Error resetting Cap widget:', e);
                }

                const tokenInput = form.querySelector('input[name="cap-token"]');
                if (tokenInput) {
                    tokenInput.value = '';
                }
            }
        });
        console.log('Cap XHR handler registered successfully');
    };

    if (window.GravFormXHR && window.GravFormXHR.captcha) {
        registerCapHandler();
    } else {
        document.addEventListener('DOMContentLoaded', function () {
            setTimeout(registerCapHandler, 100);
        });
    }
})();
