(function() {
    function refreshNonces() {
        var nonces = document.querySelectorAll('.form-nonce-field');
        if (nonces.length === 0) return;

        var actions = {};
        nonces.forEach(function(field) {
            var action = field.getAttribute('data-nonce-action');
            if (!actions[action]) actions[action] = [];
            actions[action].push(field);
        });

        Object.keys(actions).forEach(function(action) {
             try {
                 var urlObj = new URL(window.location.href);
                 urlObj.searchParams.set('task', 'get-nonce');
                 urlObj.searchParams.set('action', action);
                 var fetchUrl = urlObj.toString();

                 fetch(fetchUrl, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                 })
                 .then(function(response) { return response.json(); })
                 .then(function(data) {
                    if (data.nonce) {
                        actions[action].forEach(function(field) {
                            field.value = data.nonce;
                        });
                    }
                 })
                 .catch(function(e) {
                     console.error('Grav Form Plugin: Failed to refresh nonce', e);
                 });
             } catch (e) {
                 console.error('Grav Form Plugin: URL parsing failed', e);
             }
        });
    }

    // Refresh based on configured interval (default to 15 minutes)
    var interval = (window.GravForm && window.GravForm.refresh_nonce_interval) || 900000;

    function scheduleRefresh() {
        var nonces = document.querySelectorAll('.form-nonce-field');
        if (nonces.length === 0) return;

        var parsed = Number(interval);
        var delay = !isNaN(parsed) && parsed > 0 ? parsed : 900000;
        delay = Math.max(delay, 1000);
        setTimeout(function() {
            refreshNonces();
            setInterval(refreshNonces, delay);
        }, delay);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', scheduleRefresh);
    } else {
        scheduleRefresh();
    }

})();
