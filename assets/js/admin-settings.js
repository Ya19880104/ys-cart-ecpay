(function () {
    'use strict';

    function initializeSources() {
        document.querySelectorAll('[data-ys-ecpay-logistics-source]').forEach(function (select) {
            var fields = document.getElementById(select.getAttribute('aria-controls'));
            if (!fields) {
                return;
            }
            function syncSource() {
                var separate = select.value === 'separate';
                fields.hidden = !separate;
                fields.querySelectorAll('input, select, textarea, button').forEach(function (input) {
                    input.disabled = !separate;
                });
            }
            select.addEventListener('change', syncSource);
            syncSource();
        });
    }

    function initializeProvider() {
        var toggle = document.getElementById('ys-ec-ecpay-enabled');
        var settings = document.getElementById('ys-ec-ecpay-provider-settings');
        if (!toggle || !settings) {
            return;
        }
        function syncProvider() {
            settings.hidden = !toggle.checked;
            toggle.setAttribute('aria-expanded', toggle.checked ? 'true' : 'false');
        }
        toggle.addEventListener('change', syncProvider);
        syncProvider();
    }

    function initialize() {
        initializeSources();
        initializeProvider();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialize, { once: true });
    } else {
        initialize();
    }
}());
