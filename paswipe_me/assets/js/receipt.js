/**
 * Receipt print page: opens the print dialog once the page is ready, and
 * again from the Print button.
 */
(function () {
    'use strict';

    var button = document.querySelector('[data-receipt-print]');
    if (button) {
        button.addEventListener('click', function () { window.print(); });
    }
    window.addEventListener('load', function () { window.print(); });
})();
