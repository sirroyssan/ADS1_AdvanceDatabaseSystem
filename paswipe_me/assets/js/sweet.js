/**
 * Sweet - small "sweet alert" style dialogs, no external library.
 *
 *   Sweet.alert({ type: 'success'|'error'|'warning'|'info', title, messages: [..], timer: ms })
 *   Sweet.confirm({ type: 'danger'|'warning'|'question', title, text, confirmText, cancelText }) -> Promise<boolean>
 *
 * On page load the server's flash messages (.toast-stack [data-alert]) are
 * shown in one dialog. Without JavaScript they stay as inline alerts.
 */
(function () {
    'use strict';

    var ICONS = {
        success: '<svg viewBox="0 0 52 52" aria-hidden="true"><circle class="sweet-ring" cx="26" cy="26" r="24"/><path class="sweet-mark" d="M15 27l7 7 15-16"/></svg>',
        error: '<svg viewBox="0 0 52 52" aria-hidden="true"><circle class="sweet-ring" cx="26" cy="26" r="24"/><path class="sweet-mark" d="M18 18l16 16M34 18L18 34"/></svg>',
        warning: '<svg viewBox="0 0 52 52" aria-hidden="true"><circle class="sweet-ring" cx="26" cy="26" r="24"/><path class="sweet-mark" d="M26 14v16"/><circle class="sweet-dot" cx="26" cy="37" r="2.2"/></svg>',
        info: '<svg viewBox="0 0 52 52" aria-hidden="true"><circle class="sweet-ring" cx="26" cy="26" r="24"/><circle class="sweet-dot" cx="26" cy="16" r="2.2"/><path class="sweet-mark" d="M26 23v15"/></svg>',
        question: '<svg viewBox="0 0 52 52" aria-hidden="true"><circle class="sweet-ring" cx="26" cy="26" r="24"/><path class="sweet-mark" d="M20 20a6 6 0 1 1 8.5 5.5c-1.7.8-2.5 2-2.5 3.8V31"/><circle class="sweet-dot" cx="26" cy="37" r="2.2"/></svg>',
        danger: '<svg viewBox="0 0 52 52" aria-hidden="true"><circle class="sweet-ring" cx="26" cy="26" r="24"/><path class="sweet-mark" d="M18 19h16M23 19v-3h6v3M20 19l1 16h10l1-16M24 23v8M28 23v8"/></svg>'
    };

    var open = null; // only one dialog at a time

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    }

    function show(options) {
        return new Promise(function (resolve) {
            if (open) open.close(false);

            var lastFocus = document.activeElement;
            var type = options.type || 'info';
            var id = 'sweet-' + Date.now();

            var overlay = el('div', 'sweet');
            var panel = el('div', 'sweet__panel sweet__panel--' + type);
            panel.setAttribute('role', options.confirm ? 'alertdialog' : 'dialog');
            panel.setAttribute('aria-modal', 'true');
            panel.setAttribute('aria-labelledby', id + '-title');
            panel.setAttribute('aria-describedby', id + '-text');

            var icon = el('div', 'sweet__icon sweet__icon--' + type);
            icon.innerHTML = ICONS[type] || ICONS.info;
            panel.appendChild(icon);

            var title = el('h2', 'sweet__title', options.title || '');
            title.id = id + '-title';
            panel.appendChild(title);

            var body = el('div', 'sweet__text');
            body.id = id + '-text';
            var messages = options.messages || (options.text ? [options.text] : []);
            if (messages.length === 1) {
                body.appendChild(el('p', '', messages[0]));
            } else if (messages.length > 1) {
                var list = el('ul', 'sweet__list');
                messages.forEach(function (m) {
                    var item = el('li', 'sweet__item sweet__item--' + (m.type || type), m.text || m);
                    list.appendChild(item);
                });
                body.appendChild(list);
            }
            panel.appendChild(body);

            var actions = el('div', 'sweet__actions');
            var cancel = null;
            if (options.confirm) {
                cancel = el('button', 'btn btn--outline', options.cancelText || 'Cancel');
                cancel.type = 'button';
                actions.appendChild(cancel);
            }
            var ok = el('button', 'btn ' + (type === 'danger' || type === 'error' ? 'btn--danger' : 'btn--primary'), options.confirmText || 'OK');
            ok.type = 'button';
            actions.appendChild(ok);
            panel.appendChild(actions);

            var bar = null;
            if (options.timer) {
                bar = el('div', 'sweet__timer');
                bar.style.animationDuration = options.timer + 'ms';
                panel.appendChild(bar);
            }

            overlay.appendChild(panel);
            document.body.appendChild(overlay);
            document.body.classList.add('modal-open');
            requestAnimationFrame(function () { overlay.classList.add('is-visible'); });

            var timer = null;
            var dialog = {
                close: function (result) {
                    if (open !== dialog) return;
                    open = null;
                    clearTimeout(timer);
                    overlay.classList.remove('is-visible');
                    overlay.classList.add('is-leaving');
                    document.body.classList.remove('modal-open');
                    setTimeout(function () { overlay.remove(); }, 200);
                    if (lastFocus && document.body.contains(lastFocus)) lastFocus.focus();
                    resolve(result);
                }
            };
            open = dialog;

            ok.addEventListener('click', function () { dialog.close(true); });
            if (cancel) cancel.addEventListener('click', function () { dialog.close(false); });
            overlay.addEventListener('click', function (event) {
                if (event.target === overlay) dialog.close(false);
            });
            overlay.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') {
                    event.preventDefault();
                    dialog.close(false);
                }
                if (event.key === 'Tab') { // keep focus inside
                    var buttons = actions.querySelectorAll('button');
                    var first = buttons[0];
                    var last = buttons[buttons.length - 1];
                    if (event.shiftKey && document.activeElement === first) {
                        event.preventDefault();
                        last.focus();
                    } else if (!event.shiftKey && document.activeElement === last) {
                        event.preventDefault();
                        first.focus();
                    }
                }
            });
            // Pause the auto-close while the pointer is on the dialog.
            if (options.timer) {
                timer = setTimeout(function () { dialog.close(true); }, options.timer);
                panel.addEventListener('mouseenter', function () {
                    clearTimeout(timer);
                    bar.style.animationPlayState = 'paused';
                });
                panel.addEventListener('mouseleave', function () {
                    timer = setTimeout(function () { dialog.close(true); }, 1500);
                    bar.style.animationDuration = '1500ms';
                    bar.style.animationPlayState = 'running';
                });
            }

            // Safer default focus: Cancel for confirmations, OK for alerts.
            (cancel || ok).focus();
        });
    }

    window.Sweet = {
        alert: function (options) { return show(options || {}); },
        confirm: function (options) { return show(Object.assign({ type: 'question', confirm: true }, options || {})); }
    };

    /* Flash messages from the server -> one sweet dialog. */
    function showFlashes() {
        var alerts = Array.prototype.slice.call(document.querySelectorAll('.toast-stack [data-alert]'));
        if (!alerts.length) return;

        var rank = { error: 4, warning: 3, info: 2, success: 1 };
        var type = 'success';
        var messages = alerts.map(function (alert) {
            var kind = ['error', 'warning', 'info', 'success'].filter(function (k) {
                return alert.classList.contains('alert--' + k);
            })[0] || 'info';
            if (rank[kind] > rank[type]) type = kind;
            var span = alert.querySelector('span');
            return { type: kind, text: (span ? span.textContent : alert.textContent).trim() };
        });

        // The dialog replaces the inline alerts (they stay if JavaScript is off).
        alerts.forEach(function (alert) { alert.remove(); });

        var titles = { success: 'Success!', error: 'Something went wrong', warning: 'Please check', info: 'Please note' };
        Sweet.alert({
            type: type,
            title: titles[type],
            messages: messages.length === 1 ? [messages[0].text] : messages,
            confirmText: 'OK',
            timer: type === 'success' ? 3500 : 0
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', showFlashes);
    } else {
        showFlashes();
    }
})();
