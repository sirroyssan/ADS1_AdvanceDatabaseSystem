/**
 * Submit Payment (borrower portal): tick an item -> its amount box opens and
 * is filled with what is left to assign (never more than the item owes);
 * the summary shows assigned / not assigned against the amount paid.
 */
(function () {
    'use strict';

    // Also runs when Submit Payment is opened in a modal (assets/js/modal-form.js).
    window.App.register(function (scope) {
    var box = scope.querySelector('[data-pay-for]');
    var paidInput = box && box.closest('form') ? box.closest('form').querySelector('[name="amount"]') : null;
    if (!box || !paidInput || !window.App.once(box, 'PayFor')) return;

    var rows = Array.prototype.slice.call(box.querySelectorAll('[data-pay-check]')).map(function (check) {
        var tr = check.closest('tr');
        return { check: check, amount: tr.querySelector('[data-pay-amount]') };
    });

    function num(value) {
        var n = parseFloat(String(value || '').replace(/[^0-9.]/g, ''));
        return isNaN(n) ? 0 : n;
    }

    function peso(n) {
        return '₱' + n.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function assigned(except) {
        return rows.reduce(function (sum, r) {
            return r !== except && r.check.checked ? sum + num(r.amount.value) : sum;
        }, 0);
    }

    function refresh() {
        var paid = num(paidInput.value);
        var total = assigned(null);
        var set = function (sel, text) { var el = box.querySelector(sel); if (el) el.textContent = text; };
        set('[data-pay-assigned]', peso(total));
        set('[data-pay-total]', peso(paid));
        set('[data-pay-rest]', peso(Math.max(0, paid - total)));
        var over = total > paid + 0.004;
        box.classList.toggle('is-over', over);
    }

    rows.forEach(function (r) {
        r.check.addEventListener('change', function () {
            r.amount.disabled = !r.check.checked;
            if (r.check.checked && !num(r.amount.value)) {
                var left = Math.max(0, num(paidInput.value) - assigned(r));
                var owed = num(r.amount.getAttribute('data-owed'));
                var fill = left > 0 ? Math.min(owed, left) : owed;
                r.amount.value = fill.toFixed(2);
            }
            if (r.check.checked) r.amount.focus();
            refresh();
        });
        r.amount.addEventListener('input', refresh);
    });
    paidInput.addEventListener('input', refresh);
    refresh();
    });
})();
