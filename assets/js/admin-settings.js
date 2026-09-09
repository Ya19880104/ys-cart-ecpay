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

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeSources, { once: true });
    } else {
        initializeSources();
    }
}());
