function attachFormSubmitListener(formId) {
    var form = document.getElementById(formId);
    if (!form) {
        console.warn('Form with ID "' + formId + '" not found.');
        return;
    }
    form.addEventListener('submit', function(e) {
        // Prevent standard form submission
        e.preventDefault();
        // Submit the form via Ajax
        var xhr = new XMLHttpRequest();
        xhr.open(form.getAttribute('method'), form.getAttribute('action'));
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onload = function() {
            if (xhr.status === 200) {
                form.innerHTML = xhr.responseText; // Update the current form's innerHTML
            } else {
                // Handle HTTP error responses (optional)
                console.error('Form submission failed with status: ' + xhr.status);
            }
        };
        xhr.send(new URLSearchParams(new FormData(form)).toString());
    });
}
