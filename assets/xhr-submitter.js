(function() {
    // Function to handle AJAX submission (no changes here)
    function submitFormViaAjax(form, token = null) {
        var xhr = new XMLHttpRequest();
        xhr.open(form.getAttribute('method'), form.getAttribute('action'));
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onload = function() {
            if (xhr.status === 200) {
                form.innerHTML = xhr.responseText;
            } else {
                console.error('Form submission failed with status: ' + xhr.status);
            }
        };

        let formData = new FormData(form);
        if (token) {
            formData.append('data[token]', token);
        }

        xhr.send(new URLSearchParams(formData).toString());
    }

    // Function to attach listener to a specific form
    function attachFormSubmitListener(form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();

            if (typeof grecaptcha !== 'undefined' && form.querySelector('.g-recaptcha')) {
                if (form.submitRecaptchaForm) {
                    form.submitRecaptchaForm();
                } else {
                    grecaptcha.execute();
                }
            } else {
                submitFormViaAjax(form);
            }
        });
    }

    // Function to set data-xhr-submit attribute
    function setXhrSubmitAttribute(form) {
        if (form.dataset.hasOwnProperty('xhrSubmit')) {
            return;
        }
        form.dataset.xhrSubmit = 'true';
    }

    // Initialize only if there are forms with xhr_submit: true
    document.addEventListener('DOMContentLoaded', function() {
        var xhrForms = document.querySelectorAll('form[data-xhr-submit="true"]');
        if (xhrForms.length > 0) {

            // Attach listeners to each form individually
            xhrForms.forEach(function(form) {
                setXhrSubmitAttribute(form);
                attachFormSubmitListener(form);
            });
        }
    });
})();
