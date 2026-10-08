/**
 * Shared behaviour for all record modules (loaded after dashboard.js):
 *  - client-side required-field check for forms marked data-validate
 *  - dependent dropdowns: <select data-depends-on="f_parent"> whose options
 *    carry data-parent="<parent value>" are filtered to the chosen parent
 *  - automatic totals: <input data-sum="f_a,f_b"> / data-product="f_q,f_p"
 *    / data-divide="f_total,f_count" fill themselves until edited by hand
 * The server validates everything again.
 */
(function () {
    'use strict';

    /* ------------------------------------------------------------------
     * Required fields
     * ------------------------------------------------------------------ */
    function fieldError(input, message) {
        var error = document.getElementById(input.id + '-error');
        input.classList.toggle('is-invalid', Boolean(message));
        input.setAttribute('aria-invalid', message ? 'true' : 'false');
        if (error) {
            error.textContent = message;
            error.hidden = !message;
        }
    }

    function labelFor(input) {
        var label = document.querySelector('label[for="' + input.id + '"]');
        return label ? label.textContent.replace('*', '').trim() : 'This field';
    }

    document.querySelectorAll('form[data-validate]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            var first = null;
            form.querySelectorAll('[required]').forEach(function (input) {
                if (input.disabled) return;
                var empty = input.type === 'checkbox' ? false : input.value.trim() === '';
                fieldError(input, empty ? labelFor(input) + ' is required.' : '');
                if (empty && !first) first = input;
            });
            if (first) {
                event.preventDefault();
                event.stopImmediatePropagation();
                first.focus();
            }
        }, true);

        form.querySelectorAll('input, select, textarea').forEach(function (input) {
            input.addEventListener('input', function () {
                if (input.classList.contains('is-invalid') && input.value.trim() !== '') {
                    fieldError(input, '');
                }
            });
        });
    });

    /* ------------------------------------------------------------------
     * Dependent dropdowns
     * ------------------------------------------------------------------ */
    document.querySelectorAll('select[data-depends-on]').forEach(function (child) {
        var parent = document.getElementById(child.getAttribute('data-depends-on'));
        if (!parent) return;
        var always = (child.getAttribute('data-depends-always') || '').split(',');

        function apply() {
            var value = parent.value;
            Array.prototype.forEach.call(child.options, function (option) {
                if (option.value === '') return;
                var owner = option.getAttribute('data-parent') || '';
                var show = value === '' || owner === value || always.indexOf(owner) !== -1;
                option.hidden = !show;
                option.disabled = !show;
            });
            var selected = child.options[child.selectedIndex];
            if (selected && selected.disabled) {
                child.value = '';
            }
        }

        parent.addEventListener('change', apply);
        apply();
    });

    /* ------------------------------------------------------------------
     * Automatic totals
     * ------------------------------------------------------------------ */
    function number(id) {
        var el = document.getElementById(id);
        if (!el) return 0;
        var value = parseFloat(String(el.value).replace(/[,₱\s]/g, ''));
        return isNaN(value) ? 0 : value;
    }

    function bindCalc(target, attr, compute) {
        var ids = target.getAttribute(attr).split(',');
        var edited = target.value.trim() !== '' && target.hasAttribute('data-keep');

        target.addEventListener('input', function () {
            edited = target.value.trim() !== '';
        });

        function update() {
            if (edited) return;
            var result = compute(ids.map(number));
            target.value = isFinite(result) && result > 0 ? result.toFixed(2) : '';
        }

        ids.forEach(function (id) {
            var source = document.getElementById(id);
            if (source) source.addEventListener('input', update);
        });
    }

    document.querySelectorAll('[data-sum]').forEach(function (target) {
        bindCalc(target, 'data-sum', function (values) {
            return values.reduce(function (a, b) { return a + b; }, 0);
        });
    });

    document.querySelectorAll('[data-product]').forEach(function (target) {
        bindCalc(target, 'data-product', function (values) {
            return values.reduce(function (a, b) { return a * b; }, 1);
        });
    });

    document.querySelectorAll('[data-divide]').forEach(function (target) {
        bindCalc(target, 'data-divide', function (values) {
            return values[1] > 0 ? values[0] / values[1] : 0;
        });
    });

    /* ------------------------------------------------------------------
     * Inline "add" panels: <button data-toggle-panel="panel-id">
     * ------------------------------------------------------------------ */
    document.querySelectorAll('[data-toggle-panel]').forEach(function (button) {
        var panel = document.getElementById(button.getAttribute('data-toggle-panel'));
        if (!panel) return;
        button.setAttribute('aria-controls', panel.id);
        button.setAttribute('aria-expanded', panel.hidden ? 'false' : 'true');
        button.addEventListener('click', function () {
            panel.hidden = !panel.hidden;
            button.setAttribute('aria-expanded', panel.hidden ? 'false' : 'true');
            if (!panel.hidden) {
                var first = panel.querySelector('input:not([type=hidden]), select, textarea');
                if (first) first.focus();
            }
        });
    });
})();
