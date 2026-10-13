/**
 * "What it paid" (pages/transactions/repayment_parts.php): live total of the
 * split. Shows how much is split and what is left for the oldest installments;
 * a row above what it owes, or a split above the repayment, turns red and Save
 * is disabled until it is fixed.
 */
(function () {
    'use strict';

    // Also runs when the form is opened in a modal (assets/js/modal-form.js).
    window.App.register(function (scope) {
        var table = scope.querySelector('[data-split]');
        if (!table || !window.App.once(table, 'Split')) return;
        var form = table.closest('form');
        var save = form ? form.querySelector('button[type="submit"]') : null;
        var total = parseFloat(table.getAttribute('data-split-total')) || 0;
        var inputs = Array.prototype.slice.call(table.querySelectorAll('input[name^="part["]'));

        function num(v) {
            var n = parseFloat(String(v || '').replace(/[^0-9.]/g, ''));
            return isNaN(n) ? 0 : n;
        }
        function peso(n) {
            return '₱' + n.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        function refresh() {
            var sum = 0;
            var bad = false;
            inputs.forEach(function (input) {
                var v = num(input.value);
                var over = v > num(input.getAttribute('data-owes')) + 0.004;
                input.classList.toggle('is-invalid', over);
                bad = bad || over;
                sum += v;
            });
            var rest = Math.round((total - sum) * 100) / 100;
            var overTotal = rest < -0.004;
            table.querySelector('[data-split-assigned]').textContent = peso(sum);
            table.querySelector('[data-split-rest]').textContent = peso(Math.abs(rest));
            table.querySelector('[data-split-rest-label]').textContent = overTotal ? 'too much by' : 'left for the oldest installments';
            table.classList.toggle('is-over', overTotal || bad);
            table.querySelector('tfoot').classList.toggle('text-danger', overTotal || bad);
            if (save) save.disabled = overTotal || bad;
        }

        inputs.forEach(function (input) { input.addEventListener('input', refresh); });
        refresh();
    });
})();
