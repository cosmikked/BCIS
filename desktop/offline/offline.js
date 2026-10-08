'use strict';

// Local page shown when the server cannot be reached (FR-SHL-005).
// It receives the server URL from the shell as ?url=... and links back to it.
// It stores nothing and sends nothing.

(function () {
    var params = new URLSearchParams(window.location.search);
    var target = params.get('url');
    var urlLabel = document.getElementById('server-url');
    var message = document.getElementById('message');
    var retry = document.getElementById('retry');
    var status = document.getElementById('status');

    var valid = false;
    if (target) {
        try {
            var parsed = new URL(target);
            valid = parsed.protocol === 'http:' || parsed.protocol === 'https:';
        } catch {
            valid = false;
        }
    }

    if (valid) {
        urlLabel.textContent = target;
    } else {
        message.textContent = 'The server address is missing or invalid. Ask the administrator to fix config.json.';
        retry.disabled = true;
        return;
    }

    retry.addEventListener('click', function () {
        retry.disabled = true;
        status.textContent = 'Trying to reach the server...';
        // If the server is still down the shell shows this page again.
        window.location.href = target;
    });
})();
