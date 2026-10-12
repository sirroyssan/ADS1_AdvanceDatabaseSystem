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

    // Runs on the page and again on content added later (modal forms, refreshed lists).
    App.register(function (scope) {
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

        scope.querySelectorAll('form[data-validate]').forEach(function (form) {
            if (!App.once(form, 'M1')) return;
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
        scope.querySelectorAll('select[data-depends-on]').forEach(function (child) {
            if (!App.once(child, 'M2')) return;
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

        /* ------------------------------------------------------------------
         * Amounts are easy to check: 400000 becomes 400,000.00 when you leave
         * the box (data-scale decimals; the server removes the commas again).
         * A value with more decimals than allowed is left as typed, so the
         * form's own error shows instead of a silent rounding.
         * ------------------------------------------------------------------ */
        function formatAmount(input, value) {
            var raw = String(value === undefined ? input.value : value).replace(/[,₱\s]/g, '');
            var scale = parseInt(input.getAttribute('data-scale') || '2', 10);
            var m = /^(-?)(\d*)(?:\.(\d*))?$/.exec(raw);
            if (raw === '' || !m || (m[2] === '' && !m[3]) || (m[3] || '').length > scale) {
                if (value !== undefined) input.value = value;
                return;
            }
            var n = Number(raw);
            if (!isFinite(n)) return;
            input.value = n.toLocaleString('en-US', { minimumFractionDigits: scale, maximumFractionDigits: scale });
        }
        App.formatAmount = formatAmount;

        scope.querySelectorAll('input.input-num').forEach(function (input) {
            if (!App.once(input, 'M10')) return;
            input.addEventListener('blur', function () { formatAmount(input); });
            if (document.activeElement !== input) formatAmount(input);
        });

        // A saved value (data-keep) is kept until one of its sources changes; a value
        // typed by hand in this form is never overwritten. A calculated value passes
        // the change on, so totals that depend on it follow (total -> monthly payment).
        function bindCalc(target, attr, compute) {
            var ids = target.getAttribute(attr).split(/[,|]/);
            var typed = false;

            target.addEventListener('input', function () {
                if (target._calculating) return;
                typed = target.value.trim() !== '';
            });

            function update() {
                if (typed) return;
                var result = compute(ids.map(number));
                var before = target.value;
                if (isFinite(result) && result > 0) {
                    formatAmount(target, result.toFixed(2));
                } else {
                    target.value = '';
                }
                if (target.value !== before) {
                    target._calculating = true;
                    target.dispatchEvent(new Event('input', { bubbles: true }));
                    target._calculating = false;
                }
            }

            ids.forEach(function (id) {
                var source = document.getElementById(id);
                if (source) {
                    source.addEventListener('input', update);
                    if (source.tagName === 'SELECT') source.addEventListener('change', update);
                }
            });
        }

        /* Add-on interest: data-addon="f_a,f_b,f_c|f_rate|f_term" = principal (the first
           of a, b, c above zero) + principal x rate % x term (months). */
        scope.querySelectorAll('[data-addon]').forEach(function (target) {
            if (!App.once(target, 'M11')) return;
            var groups = target.getAttribute('data-addon').split('|');
            var principals = groups[0].split(',').length;
            bindCalc(target, 'data-addon', function (values) {
                var principal = values.slice(0, principals).filter(function (v) { return v > 0; })[0] || 0;
                var rate = values[principals];
                var term = values[principals + 1];
                return principal > 0 && term > 0 ? Math.round((principal + principal * rate / 100 * term) * 100) / 100 : 0;
            });
        });

        scope.querySelectorAll('[data-sum]').forEach(function (target) {
            if (!App.once(target, 'M3')) return;
            bindCalc(target, 'data-sum', function (values) {
                return values.reduce(function (a, b) { return a + b; }, 0);
            });
        });

        scope.querySelectorAll('[data-product]').forEach(function (target) {
            if (!App.once(target, 'M4')) return;
            bindCalc(target, 'data-product', function (values) {
                return values.reduce(function (a, b) { return a * b; }, 1);
            });
        });

        scope.querySelectorAll('[data-divide]').forEach(function (target) {
            if (!App.once(target, 'M5')) return;
            bindCalc(target, 'data-divide', function (values) {
                return values[1] > 0 ? values[0] / values[1] : 0;
            });
        });

        /* ------------------------------------------------------------------
         * Last installment date: data-end-from="f_first_due,f_term,f_frequency"
         * = first due date + (term - 1) periods, the same day of the month
         * (the server calculates it again). Read-only while it is calculated.
         * ------------------------------------------------------------------ */
        scope.querySelectorAll('[data-end-from]').forEach(function (target) {
            if (!App.once(target, 'M9')) return;
            var ids = target.getAttribute('data-end-from').split(',');
            var first = document.getElementById(ids[0]);
            var term = document.getElementById(ids[1]);
            var freq = document.getElementById(ids[2]);
            if (!first || !term) return;
            function pad(n) { return (n < 10 ? '0' : '') + n; }
            function update() {
                var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(first.value);
                var count = parseInt(term.value, 10);
                if (!m || !(count > 0)) {
                    target.readOnly = false;
                    return;
                }
                var f = freq ? freq.value : '';
                var date;
                if (f === 'weekly' || f === 'biweekly') {
                    date = new Date(Date.UTC(+m[1], +m[2] - 1, +m[3] + (f === 'weekly' ? 7 : 14) * (count - 1)));
                } else {
                    var months = (+m[2] - 1) + (f === 'quarterly' ? 3 : 1) * (count - 1);
                    var year = +m[1] + Math.floor(months / 12);
                    var month = months % 12;
                    var last = new Date(Date.UTC(year, month + 1, 0)).getUTCDate();
                    date = new Date(Date.UTC(year, month, Math.min(+m[3], last)));
                }
                target.value = date.getUTCFullYear() + '-' + pad(date.getUTCMonth() + 1) + '-' + pad(date.getUTCDate());
                target.readOnly = true;
            }
            [first, term, freq].forEach(function (el) {
                if (el) {
                    el.addEventListener('input', update);
                    el.addEventListener('change', update);
                }
            });
            update();
        });

        /* ------------------------------------------------------------------
         * Fields shown only for one value of another field:
         * data-show-if="f_payment_type=installment" (on the input) hides its form group otherwise.
         * ------------------------------------------------------------------ */
        scope.querySelectorAll('[data-show-if]').forEach(function (input) {
            if (!App.once(input, 'M8')) return;
            var rule = input.getAttribute('data-show-if').split('=');
            var source = document.getElementById(rule[0]);
            var group = input.closest('.form-group') || input;
            if (!source) return;
            function apply() {
                var show = source.value === rule[1];
                group.hidden = !show;
                input.disabled = !show;   // hidden terms are not sent
            }
            source.addEventListener('change', apply);
            apply();
        });

        /* ------------------------------------------------------------------
         * Inline "add" panels: <button data-toggle-panel="panel-id">
         * ------------------------------------------------------------------ */
        scope.querySelectorAll('[data-toggle-panel]').forEach(function (button) {
            if (!App.once(button, 'M6')) return;
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

        // Bulk selection: [data-check-all] ticks every [data-check-item] in the same form,
        // [data-check-count] shows how many are ticked, [data-check-submit] needs at least one.
        scope.querySelectorAll('[data-bulk-form]').forEach(function (form) {
            if (!App.once(form, 'M7')) return;
            var all = form.querySelector('[data-check-all]');
            var items = Array.prototype.slice.call(form.querySelectorAll('[data-check-item]'));
            var count = form.querySelector('[data-check-count]');
            var submit = form.querySelector('[data-check-submit]');

            function refresh() {
                var n = items.filter(function (box) { return box.checked; }).length;
                if (count) { count.textContent = String(n); }
                if (submit) { submit.disabled = n === 0; }
                if (all) {
                    all.checked = n > 0 && n === items.length;
                    all.indeterminate = n > 0 && n < items.length;
                }
            }

            if (all) {
                all.addEventListener('change', function () {
                    items.forEach(function (box) { box.checked = all.checked; });
                    refresh();
                });
            }
            items.forEach(function (box) { box.addEventListener('change', refresh); });
            form.addEventListener('submit', function (event) {
                if (form.dataset.confirmed === '1' || !window.Sweet) return;
                event.preventDefault();
                var mode = form.querySelector('[name="mode"]');
                var del = mode && mode.value === 'delete';
                var n = items.filter(function (box) { return box.checked; }).length;
                var what = n + ' penalt' + (n === 1 ? 'y' : 'ies');
                window.Sweet.confirm({
                    type: del ? 'danger' : 'warning',
                    title: del ? 'Delete ' + what + '?' : 'Waive ' + what + '?',
                    text: del ? 'They will be removed. This cannot be undone.' : 'What is unpaid on them will be set to zero. The records are kept.',
                    confirmText: del ? 'Delete' : 'Waive'
                }).then(function (ok) {
                    if (!ok) return;
                    form.dataset.confirmed = '1';
                    form.submit();
                });
            });
            refresh();
        });
    });
})();
