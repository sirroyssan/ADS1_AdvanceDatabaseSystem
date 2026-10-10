/**
 * Add-forms in a modal, the same way in every module.
 *
 *  <a href="…/create.php" data-modal-form>      loads that page's form into the modal
 *  <button data-modal-panel="panel-id">         shows an inline add panel in the modal
 *
 * The forms, validation and saving are the existing PHP pages and handlers:
 * requests carry "X-Modal-Form: 1", and redirect() answers them with JSON
 * ({ok, redirect, error}) instead of a redirect (includes/functions.php).
 *  - validation errors re-render the form          -> shown in the modal
 *  - an error message (flash)                      -> shown in the modal, which stays open
 *  - saved                                         -> modal closes, the page content is
 *                                                     refreshed in place and the success
 *                                                     message is shown
 * Without JavaScript the links still open the full create pages.
 */
(function () {
    'use strict';

    var HEADERS = { 'X-Modal-Form': '1' };
    var CLOSE_ICON = '<svg class="icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>';

    var modal = null;
    var titleEl;
    var subtitleEl;
    var body;
    var trigger = null;     // element that opened the modal (focus returns there)
    var panel = null;       // inline panel shown in the modal, and where it came from
    var panelHome = null;
    var dirty = false;
    var busy = false;

    function build() {
        if (modal) return;
        modal = document.createElement('div');
        modal.className = 'modal-form no-print';
        modal.hidden = true;
        modal.innerHTML =
            '<div class="modal-form__backdrop" data-modal-close></div>' +
            '<div class="modal-form__dialog" role="dialog" aria-modal="true" aria-labelledby="modal-form-title">' +
            '  <header class="modal-form__header">' +
            '    <div><h2 class="modal-form__title" id="modal-form-title"></h2><p class="modal-form__subtitle"></p></div>' +
            '    <button type="button" class="icon-button modal-form__close" data-modal-close aria-label="Close">' + CLOSE_ICON + '</button>' +
            '  </header>' +
            '  <div class="modal-form__body"></div>' +
            '</div>';
        document.body.appendChild(modal);
        titleEl = modal.querySelector('.modal-form__title');
        subtitleEl = modal.querySelector('.modal-form__subtitle');
        body = modal.querySelector('.modal-form__body');

        // Close: X, backdrop, Cancel links / buttons inside the form.
        modal.addEventListener('click', function (event) {
            var target = event.target;
            var cancel = target.closest('[data-modal-close], .form-actions a, [data-toggle-panel]');
            if (!cancel || !modal.contains(cancel)) return;
            event.preventDefault();
            event.stopPropagation();
            close(false);
        }, true);

        // Anything typed counts as unsaved data.
        body.addEventListener('input', function () { dirty = true; });
        body.addEventListener('change', function () { dirty = true; });

        body.addEventListener('submit', onSubmit);

        document.addEventListener('keydown', function (event) {
            if (modal.hidden || document.querySelector('.sweet')) return;
            if (event.key === 'Escape') {
                event.preventDefault();
                close(false);
            } else if (event.key === 'Tab') {
                trapFocus(event);
            }
        });
    }

    function trapFocus(event) {
        var items = Array.prototype.filter.call(
            modal.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]):not([type=hidden]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'),
            function (el) { return el.offsetParent !== null; }
        );
        if (!items.length) return;
        var first = items[0];
        var last = items[items.length - 1];
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    }

    function show(title, subtitle) {
        build();
        titleEl.textContent = title || 'Add new';
        subtitleEl.textContent = subtitle || '';
        subtitleEl.hidden = !subtitle;
        dirty = false;
        busy = false;
        modal.hidden = false;
        document.documentElement.classList.add('has-modal-form');
        requestAnimationFrame(function () { modal.classList.add('is-open'); });
    }

    function loading() {
        body.innerHTML = '<p class="modal-form__loading"><span class="spinner" aria-hidden="true"></span> Loading the form…</p>';
    }

    function focusFirst() {
        var field = body.querySelector('.is-invalid, [aria-invalid="true"]') ||
            body.querySelector('input:not([type=hidden]):not([disabled]):not([readonly]), select:not([disabled]), textarea:not([disabled])');
        if (field) field.focus();
        else modal.querySelector('.modal-form__close').focus();
    }

    function close(force) {
        if (!modal || modal.hidden) return;
        if (busy) return;
        if (!force && dirty && window.Sweet) {
            window.Sweet.confirm({
                type: 'warning',
                title: 'Discard what you entered?',
                text: 'The form has not been saved. Closing it loses your changes.',
                confirmText: 'Discard',
                cancelText: 'Keep editing'
            }).then(function (discard) {
                if (discard) close(true);
            });
            return;
        }

        if (panel) {
            var form = panel.matches('form') ? panel : panel.querySelector('form');
            if (form && dirty) form.reset();
            panel.hidden = true;
            if (panelHome && panelHome.parentNode) {
                panelHome.parentNode.insertBefore(panel, panelHome);
                panelHome.remove();
            }
            panel = null;
            panelHome = null;
        }

        modal.classList.remove('is-open');
        modal.hidden = true;
        body.innerHTML = '';
        dirty = false;
        document.documentElement.classList.remove('has-modal-form');
        if (trigger && document.body.contains(trigger)) trigger.focus();
        trigger = null;
    }

    function showError(message) {
        var old = body.querySelector('.modal-form__error');
        if (old) old.remove();
        var alert = document.createElement('div');
        alert.className = 'alert alert--error modal-form__error';
        alert.setAttribute('role', 'alert');
        alert.textContent = message;
        body.insertBefore(alert, body.firstChild);
        body.scrollTop = 0;
    }

    /* ------------------------------------------------------------------
     * Page content -> modal
     * ------------------------------------------------------------------ */
    function parse(html) {
        return new DOMParser().parseFromString(html, 'text/html');
    }

    function scriptPath(src) {
        try { return new URL(src, location.href).pathname; } catch (e) { return src; }
    }

    // Page scripts of the form (e.g. categories.js) that this page has not loaded yet.
    function loadScripts(doc) {
        var have = Array.prototype.map.call(document.querySelectorAll('script[src]'), function (s) { return scriptPath(s.src); });
        var missing = Array.prototype.filter.call(doc.querySelectorAll('script[src]'), function (s) {
            return have.indexOf(scriptPath(s.getAttribute('src'))) === -1;
        });
        return missing.reduce(function (chain, s) {
            return chain.then(function () {
                return new Promise(function (resolve) {
                    var tag = document.createElement('script');
                    tag.src = s.getAttribute('src');
                    tag.onload = tag.onerror = resolve;
                    document.body.appendChild(tag);
                });
            });
        }, Promise.resolve());
    }

    function renderPage(doc) {
        var main = doc.querySelector('main');
        if (!main) return false;

        var heading = main.querySelector('.page-header__title');
        var subtitle = main.querySelector('.page-header__subtitle');
        if (heading) titleEl.textContent = heading.textContent.trim();
        subtitleEl.textContent = subtitle ? subtitle.textContent.trim() : '';
        subtitleEl.hidden = !subtitle;

        body.innerHTML = '';
        // Messages for this form (e.g. "not found", "no permission") appear inline.
        main.querySelectorAll('.toast-stack [data-alert]').forEach(function (alert) {
            var close = alert.querySelector('[data-alert-close]');
            if (close) close.remove();
            alert.removeAttribute('data-alert');
            alert.classList.remove('alert--dismissible');
            body.appendChild(document.importNode(alert, true));
        });
        Array.prototype.forEach.call(main.children, function (node) {
            if (node.matches('.toast-stack, .breadcrumb, .page-header, .print-header, script')) return;
            body.appendChild(document.importNode(node, true));
        });
        return true;
    }

    function fill(html) {
        var doc = parse(html);
        if (!renderPage(doc)) {
            showError('The form could not be loaded. Please try again.');
            return Promise.resolve();
        }
        return loadScripts(doc).then(function () {
            if (window.App) window.App.init(body);
            focusFirst();
        });
    }

    function failed() {
        busy = false;
        showError('Could not reach the server. Check your connection and try again.');
    }

    /* ------------------------------------------------------------------
     * Opening
     * ------------------------------------------------------------------ */
    function openUrl(url, from) {
        trigger = from;
        show(from ? from.textContent.trim() : '', '');
        loading();
        busy = true;
        fetch(url, { headers: HEADERS, credentials: 'same-origin' }).then(function (response) {
            busy = false;
            var type = response.headers.get('Content-Type') || '';
            if (type.indexOf('application/json') !== -1) {
                // The page sent the user elsewhere instead of showing a form.
                return response.json().then(function (data) {
                    close(true);
                    if (!data.ok && window.Sweet) {
                        window.Sweet.alert({ type: 'error', title: 'Cannot open the form', messages: [data.error], confirmText: 'OK' });
                    } else {
                        location.href = data.redirect;
                    }
                });
            }
            return response.text().then(fill);
        }).catch(failed);
    }

    function openPanel(button) {
        var target = document.getElementById(button.getAttribute('data-modal-panel'));
        if (!target) return;
        trigger = button;
        var label = button.querySelector('span');
        show((label || button).textContent.trim(), button.getAttribute('data-modal-subtitle') || '');
        body.innerHTML = '';
        panelHome = document.createComment('modal panel');
        target.parentNode.insertBefore(panelHome, target);
        panel = target;
        body.appendChild(target);
        target.hidden = false;
        if (window.App) window.App.init(body);
        focusFirst();
    }

    document.addEventListener('click', function (event) {
        if (event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        var link = event.target.closest('a[data-modal-form]');
        if (link) {
            event.preventDefault();
            openUrl(link.href, link);
            return;
        }
        var button = event.target.closest('[data-modal-panel]');
        if (button) {
            event.preventDefault();
            openPanel(button);
        }
    });

    /* ------------------------------------------------------------------
     * Saving
     * ------------------------------------------------------------------ */
    function onSubmit(event) {
        var form = event.target;
        if (event.defaultPrevented || busy) {
            event.preventDefault();
            return;
        }
        event.preventDefault();
        event.stopPropagation();   // the page-level confirm / loading handlers do not apply here

        var submitter = event.submitter || form.querySelector('button[type="submit"]');
        var method = (form.getAttribute('method') || 'get').toLowerCase();
        var action = form.getAttribute('action') || location.href;

        if (method === 'get') {
            // e.g. "Fill from template": reload the form with those choices.
            var url = new URL(action, location.href);
            url.search = new URLSearchParams(new FormData(form)).toString();
            loading();
            busy = true;
            fetch(url.toString(), { headers: HEADERS, credentials: 'same-origin' })
                .then(function (response) { busy = false; return response.text(); })
                .then(fill)
                .catch(failed);
            return;
        }

        var send = function () {
            var data = new FormData(form);
            if (event.submitter && event.submitter.name) data.append(event.submitter.name, event.submitter.value);
            if (submitter && window.App && window.App.setLoading) window.App.setLoading(submitter);
            busy = true;

            fetch(action, { method: 'POST', body: data, headers: HEADERS, credentials: 'same-origin' }).then(function (response) {
                busy = false;
                var type = response.headers.get('Content-Type') || '';
                if (type.indexOf('application/json') !== -1) {
                    return response.json().then(function (result) {
                        if (submitter && window.App) window.App.resetLoading(submitter);
                        if (!result.ok) {
                            showError(result.error || 'The record was not saved. Please check the form.');
                            return;
                        }
                        if (/\/auth\//.test(result.redirect)) {
                            location.href = result.redirect;   // signed out meanwhile
                            return;
                        }
                        dirty = false;
                        close(true);
                        refreshPage();
                    });
                }
                // The form came back (validation errors): show it again, keep what was typed.
                return response.text().then(function (html) {
                    if (!response.ok && html.indexOf('<main') === -1) {
                        if (submitter && window.App) window.App.resetLoading(submitter);
                        showError('The record was not saved (error ' + response.status + '). Please try again.');
                        return;
                    }
                    if (panel) {
                        if (submitter && window.App) window.App.resetLoading(submitter);
                        showError('The record was not saved. Please check the form.');
                        return;
                    }
                    return fill(html);
                });
            }).catch(function () {
                if (submitter && window.App) window.App.resetLoading(submitter);
                failed();
            });
        };

        if (form.hasAttribute('data-confirm') && window.Sweet) {
            window.Sweet.confirm({
                type: 'question',
                title: form.getAttribute('data-confirm-title') || 'Save this record?',
                text: form.getAttribute('data-confirm'),
                confirmText: form.getAttribute('data-confirm-button') || 'Save',
                cancelText: 'Cancel'
            }).then(function (ok) { if (ok) send(); });
            return;
        }
        send();
    }

    // Reload only the page content (list, totals, related tables) in place.
    function refreshPage() {
        fetch(location.href, { credentials: 'same-origin' }).then(function (response) {
            return response.text();
        }).then(function (html) {
            var fresh = parse(html).querySelector('main');
            var main = document.querySelector('main');
            if (!fresh || !main) {
                location.reload();
                return;
            }
            main.innerHTML = fresh.innerHTML;
            if (window.App) window.App.init(main);
            if (window.Sweet && window.Sweet.showFlashes) window.Sweet.showFlashes(main);
        }).catch(function () {
            location.reload();
        });
    }
})();
