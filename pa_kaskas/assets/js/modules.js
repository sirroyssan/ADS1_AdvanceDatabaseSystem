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

        /* A choice that fills another field: <select data-fills="f_target:key"> sets f_target
           to the chosen option's data-key (e.g. a merchant fills in its category). Only when
           the user changes the choice; an empty value leaves the target as it is. */
        scope.querySelectorAll('select[data-fills]').forEach(function (select) {
            if (!App.once(select, 'M13')) return;
            select.addEventListener('change', function () {
                select.getAttribute('data-fills').split(',').forEach(function (pair) {
                    var parts = pair.split(':');
                    var target = document.getElementById(parts[0]);
                    var option = select.options[select.selectedIndex];
                    var value = option ? option.getAttribute('data-' + parts[1]) : null;
                    if (!target || !value) return;
                    if (target.tagName !== 'SELECT') {
                        // An amount box: e.g. the shared line's limit.
                        if (target.classList.contains('input-num')) formatAmount(target, value); else target.value = value;
                        target.dispatchEvent(new Event('input', { bubbles: true }));
                    } else if (Array.prototype.some.call(target.options || [], function (o) { return o.value === value; })) {
                        target.value = value;
                        target.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                });
            });
        });

        /* Shared credit limit suggestion (card form): when the bank and the limit typed match
           another card or shared line, offer to share it: <select data-suggest='[{bank, limit, value, label}]'>.
           Only while "Own limit" is chosen; one click picks the matching choice. */
        scope.querySelectorAll('select[data-suggest]').forEach(function (select) {
            if (!App.once(select, 'M14')) return;
            var list;
            try { list = JSON.parse(select.getAttribute('data-suggest') || '[]'); } catch (e) { list = []; }
            var bank = document.getElementById('f_issuer');
            var limit = document.getElementById('f_credit_limit');
            var group = select.closest('.form-group');
            if (!list.length || !bank || !limit || !group) return;
            var hint = document.createElement('div');
            hint.className = 'alert alert--info line-suggest';
            hint.hidden = true;
            group.appendChild(hint);
            function update() {
                var amount = number(limit.id);
                var match = select.value === '' && amount > 0 && list.filter(function (s) {
                    return s.bank.toLowerCase() === String(bank.value).toLowerCase() && Math.abs(parseFloat(s.limit) - amount) < 0.005;
                })[0];
                hint.hidden = !match;
                if (!match) return;
                hint.textContent = '';
                var text = document.createElement('span');
                text.textContent = match.label + ' (' + match.bank + ') has the same limit. Does the bank share it between these cards? ';
                var button = document.createElement('button');
                button.type = 'button';
                button.className = 'btn btn--sm btn--primary';
                button.textContent = 'Share the limit with ' + match.label;
                button.addEventListener('click', function () {
                    select.value = match.value;
                    select.dispatchEvent(new Event('change', { bubbles: true }));
                    hint.hidden = true;
                });
                hint.appendChild(text);
                hint.appendChild(button);
            }
            [bank, limit, select].forEach(function (el) {
                el.addEventListener('input', update);
                el.addEventListener('change', update);
            });
            limit.addEventListener('blur', update);
            update();
        });

        /* Obligation interest from a monthly rate: data-rate-interest="f_amount|f_rate|f_from|f_to"
           = amount x rate % x months; months = data-months-fixed (a cash loan's installments)
           or one a month from the obligation date to the due date (at least 1). An empty rate
           leaves the interest to be typed by hand. */
        scope.querySelectorAll('[data-rate-interest]').forEach(function (target) {
            if (!App.once(target, 'M12')) return;
            var ids = target.getAttribute('data-rate-interest').split('|');
            var el = ids.map(function (id) { return document.getElementById(id); });
            if (!el[0] || !el[1]) return;
            var fixed = parseInt(target.getAttribute('data-months-fixed') || '0', 10);
            var help = document.getElementById(target.id + '-help');
            function months() {
                if (fixed > 0) return fixed;
                var a = /^(\d{4})-(\d{2})-(\d{2})$/.exec(el[2] ? el[2].value : '');
                var b = /^(\d{4})-(\d{2})-(\d{2})$/.exec(el[3] ? el[3].value : '');
                if (!a || !b) return 1;
                var n = (+b[1] - +a[1]) * 12 + (+b[2] - +a[2]) - (+b[3] < +a[3] ? 1 : 0);
                return Math.max(1, Math.min(120, n));
            }
            function update() {
                var rateText = el[1].value.replace(/[,\s%]/g, '');
                target.readOnly = rateText !== '';
                if (rateText === '') return;
                var m = months();
                var interest = Math.round(number(el[0].id) * parseFloat(rateText || '0') / 100 * m * 100) / 100;
                formatAmount(target, isFinite(interest) ? interest.toFixed(2) : '0.00');
                if (help) help.textContent = 'Months: ' + m + (fixed > 0 ? ' (the cash loan\'s installments)' : ' (one a month from the obligation date to the due date)') + '. Calculated from the agreed rate.';
            }
            el.forEach(function (e) {
                if (!e) return;
                e.addEventListener('input', update);
                e.addEventListener('change', update);
            });
            update();
        });

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

        /* Installment plan: data-plan-terms on the interest amount. The interest, fees, total and
           monthly amount follow the chosen interest method, rate and processing fee as they are
           typed (installment_compute() calculates them again on save). Read-only while calculated. */
        scope.querySelectorAll('[data-plan-terms]').forEach(function (target) {
            if (!App.once(target, 'M12')) return;
            var get = function (id) { return document.getElementById(id); };
            var method = get('f_interest_method'), feeType = get('f_fee_type');
            var fees = get('f_fees_amount'), total = get('f_total_repayment'), monthly = get('f_installment_amount');
            if (!method || !feeType) return;
            var watched = ['f_total_principal', 'f_number_of_installments', 'f_interest_method', 'f_interest_rate', 'f_fee_type', 'f_fee_value', 'f_interest_amount'];
            function update() {
                var m = method.value, P = number('f_total_principal'), n = Math.max(1, Math.round(number('f_number_of_installments')));
                var r = number('f_interest_rate') / 100;
                target.readOnly = m !== '' && m !== 'fixed_amount';
                if (fees) fees.readOnly = feeType.value !== '';
                if (m === '' || P <= 0) return;
                var interest = number('f_interest_amount');
                if (m === 'none') interest = 0;
                else if (m === 'add_on_monthly') interest = P * r * n;
                else if (m === 'add_on_total') interest = P * r;
                else if (m === 'reducing_balance') interest = r > 0 ? (P * r / (1 - Math.pow(1 + r, -n))) * n - P : 0;
                interest = Math.round(interest * 100) / 100;
                var fee = feeType.value === 'fixed' ? number('f_fee_value') : feeType.value === 'percent' ? P * number('f_fee_value') / 100 : 0;
                fee = Math.round(fee * 100) / 100;
                if (m !== 'fixed_amount') formatAmount(target, interest.toFixed(2));
                if (fees && feeType.value !== '') formatAmount(fees, fee.toFixed(2));
                var sum = Math.round((P + interest + (feeType.value !== '' ? fee : number('f_fees_amount'))) * 100) / 100;
                if (total) formatAmount(total, sum.toFixed(2));
                if (monthly) formatAmount(monthly, (Math.round(sum / n * 100) / 100).toFixed(2));
            }
            watched.forEach(function (id) {
                var e = get(id);
                if (!e) return;
                e.addEventListener('input', update);
                e.addEventListener('change', update);
            });
            update();
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
         * Several conditions (all must hold) are separated by ";", several values by "|":
         * data-show-if="f_payment_type=installment;f_inst_interest_method=add_on_monthly|add_on_total".
         * An empty value matches an empty select ("f_inst_rule_id=").
         * ------------------------------------------------------------------ */
        scope.querySelectorAll('[data-show-if]').forEach(function (input) {
            if (!App.once(input, 'M8')) return;
            var rules = input.getAttribute('data-show-if').split(';').map(function (part) {
                var bits = part.split('=');
                return { source: document.getElementById(bits[0]), values: (bits[1] || '').split('|') };
            }).filter(function (r) { return r.source; });
            var group = input.closest('.form-group') || input;
            if (!rules.length) return;
            function apply() {
                var show = rules.every(function (r) { return r.values.indexOf(r.source.value) !== -1; });
                group.hidden = !show;
                input.disabled = !show;   // hidden terms are not sent
            }
            rules.forEach(function (r) { r.source.addEventListener('change', apply); });
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
                    // requestSubmit: inside a modal the modal saves it and the record stays open
                    // (form.submit() would skip that and leave the page).
                    if (typeof form.requestSubmit === 'function') {
                        form.requestSubmit();
                        form.dataset.confirmed = '';
                    } else {
                        form.submit();
                    }
                });
            });
            refresh();
        });
    });
})();
