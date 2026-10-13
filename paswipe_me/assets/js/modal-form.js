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
 *
 * Modals stack: a modal link or panel button inside an open modal opens a new
 * modal on top and the one below stays open (e.g. "Apply penalty" from an
 * obligation). Closing the top one returns to the one below; saving in it
 * reloads the one below (and the page behind) so the change shows at once.
 * Without JavaScript the links still open the full pages.
 */
(function () {
    'use strict';

    var HEADERS = { 'X-Modal-Form': '1' };
    var CLOSE_ICON = '<svg class="icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>';
    var BASE_Z = 150;       // under the sweet dialogs (200); each layer sits 5 above the one below

    var stack = [];         // open modals, the top one last
    var counter = 0;

    function top() {
        return stack.length ? stack[stack.length - 1] : null;
    }

    /* ------------------------------------------------------------------
     * One modal layer
     * ------------------------------------------------------------------ */
    function Layer() {
        var id = 'modal-form-title-' + (++counter);
        this.el = document.createElement('div');
        this.el.className = 'modal-form no-print';
        this.el.hidden = true;
        this.el.innerHTML =
            '<div class="modal-form__backdrop" data-modal-close></div>' +
            '<div class="modal-form__dialog" role="dialog" aria-modal="true" aria-labelledby="' + id + '">' +
            '  <header class="modal-form__header">' +
            '    <div><h2 class="modal-form__title" id="' + id + '"></h2><p class="modal-form__subtitle"></p></div>' +
            '    <button type="button" class="icon-button modal-form__close" data-modal-close aria-label="Close">' + CLOSE_ICON + '</button>' +
            '  </header>' +
            '  <div class="modal-form__body"></div>' +
            '</div>';
        document.body.appendChild(this.el);
        this.titleEl = this.el.querySelector('.modal-form__title');
        this.subtitleEl = this.el.querySelector('.modal-form__subtitle');
        this.body = this.el.querySelector('.modal-form__body');
        this.trigger = null;     // element that opened the modal (focus returns there)
        this.panel = null;       // inline panel shown in the modal, and where it came from
        this.panelHome = null;
        this.url = null;         // page loaded into the modal (reloaded after a modal above saves)
        this.dirty = false;
        this.busy = false;

        var layer = this;
        // Close: X, backdrop, Cancel links / buttons inside the form.
        this.el.addEventListener('click', function (event) {
            var target = event.target;
            // A panel button inside a view (e.g. "Override amount") shows / hides its panel in place;
            // only the Cancel of a panel opened as the modal itself closes the modal.
            var toggle = target.closest('[data-toggle-panel]');
            if (toggle && layer.el.contains(toggle)) {
                var inline = layer.body.querySelector('#' + CSS.escape(toggle.getAttribute('data-toggle-panel')));
                if (inline && inline !== layer.panel) {
                    event.preventDefault();
                    event.stopPropagation();
                    inline.hidden = !inline.hidden;
                    if (!inline.hidden) {
                        if (window.App) window.App.init(inline);
                        inline.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
                        var first = inline.querySelector('input:not([type=hidden]), select, textarea');
                        if (first) first.focus();
                    }
                    return;
                }
            }
            var cancel = target.closest('[data-modal-close], .form-actions a, [data-toggle-panel]');
            if (!cancel || !layer.el.contains(cancel)) return;
            event.preventDefault();
            event.stopPropagation();
            layer.close(false);
        }, true);

        // Anything typed counts as unsaved data.
        this.body.addEventListener('input', function () { layer.dirty = true; });
        this.body.addEventListener('change', function () { layer.dirty = true; });
        this.body.addEventListener('submit', function (event) { onSubmit(layer, event); });
    }

    Layer.prototype.show = function (title, subtitle) {
        this.titleEl.textContent = title || 'Add new';
        this.subtitleEl.textContent = subtitle || '';
        this.subtitleEl.hidden = !subtitle;
        this.dirty = false;
        this.busy = false;
        this.el.style.zIndex = String(BASE_Z + (stack.length - 1) * 5);
        this.el.hidden = false;
        document.documentElement.classList.add('has-modal-form');
        var el = this.el;
        requestAnimationFrame(function () { el.classList.add('is-open'); });
    };

    Layer.prototype.loading = function () {
        this.body.innerHTML = '<p class="modal-form__loading"><span class="spinner" aria-hidden="true"></span> Loading the form…</p>';
    };

    Layer.prototype.focusFirst = function () {
        var field = this.body.querySelector('.is-invalid, [aria-invalid="true"]') ||
            this.body.querySelector('input:not([type=hidden]):not([disabled]):not([readonly]), select:not([disabled]), textarea:not([disabled])');
        if (field) field.focus();
        else this.el.querySelector('.modal-form__close').focus();
    };

    Layer.prototype.close = function (force) {
        if (this.el.hidden || this.busy) return;
        if (top() !== this) return;   // only the top modal closes
        var layer = this;
        if (!force && this.dirty && window.Sweet) {
            window.Sweet.confirm({
                type: 'warning',
                title: 'Discard what you entered?',
                text: 'The form has not been saved. Closing it loses your changes.',
                confirmText: 'Discard',
                cancelText: 'Keep editing'
            }).then(function (discard) {
                if (discard) layer.close(true);
            });
            return;
        }

        if (this.panel) {
            var form = this.panel.matches('form') ? this.panel : this.panel.querySelector('form');
            if (form && this.dirty) form.reset();
            this.panel.hidden = true;
            if (this.panelHome && this.panelHome.parentNode) {
                this.panelHome.parentNode.insertBefore(this.panel, this.panelHome);
                this.panelHome.remove();
            }
            this.panel = null;
            this.panelHome = null;
        }

        stack.pop();
        this.el.remove();
        if (!stack.length) document.documentElement.classList.remove('has-modal-form');
        if (this.trigger && document.body.contains(this.trigger)) this.trigger.focus();
        else if (top()) top().focusFirst();
    };

    Layer.prototype.showError = function (message) {
        var old = this.body.querySelector('.modal-form__error');
        if (old) old.remove();
        var alert = document.createElement('div');
        alert.className = 'alert alert--error modal-form__error';
        alert.setAttribute('role', 'alert');
        alert.textContent = message;
        this.body.insertBefore(alert, this.body.firstChild);
        this.body.scrollTop = 0;
    };

    Layer.prototype.failed = function () {
        this.busy = false;
        this.showError('Could not reach the server. Check your connection and try again.');
    };

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

    Layer.prototype.render = function (doc) {
        var main = doc.querySelector('main');
        if (!main) return false;
        var body = this.body;

        var heading = main.querySelector('.page-header__title');
        var subtitle = main.querySelector('.page-header__subtitle');
        if (heading) this.titleEl.textContent = heading.textContent.trim();
        this.subtitleEl.textContent = subtitle ? subtitle.textContent.trim() : '';
        this.subtitleEl.hidden = !subtitle;

        body.innerHTML = '';
        // A record's own actions (Edit, Pay, …) stay available at the top of a view modal;
        // Back and Print only make sense on the full page.
        var actions = main.querySelector('.page-header__actions');
        if (actions) {
            var bar = document.importNode(actions, true);
            bar.className = 'modal-form__actions no-print';
            bar.querySelectorAll('[data-print]').forEach(function (el) { el.remove(); });
            bar.querySelectorAll('a').forEach(function (a) {
                if (/^\s*back\s*$/i.test(a.textContent)) a.remove();
            });
            if (bar.children.length) body.appendChild(bar);
        }
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
    };

    Layer.prototype.fill = function (html, keepScroll) {
        var layer = this;
        var doc = parse(html);
        var scroll = this.body.scrollTop;
        if (!this.render(doc)) {
            this.showError('The form could not be loaded. Please try again.');
            return Promise.resolve();
        }
        return loadScripts(doc).then(function () {
            if (window.App) window.App.init(layer.body);
            if (keepScroll) layer.body.scrollTop = scroll;
            else layer.focusFirst();
        });
    };

    // Reload the page shown in this modal (after a modal above it saved something).
    Layer.prototype.reload = function () {
        if (!this.url || this.panel) return;
        var layer = this;
        fetch(this.url, { headers: HEADERS, credentials: 'same-origin' }).then(function (response) {
            var type = response.headers.get('Content-Type') || '';
            if (type.indexOf('application/json') !== -1) {
                // The record is gone (e.g. deleted): close its modal.
                if (layer === top()) {
                    layer.busy = false;
                    layer.close(true);
                }
                return null;
            }
            return response.text();
        }).then(function (html) {
            if (html) {
                layer.dirty = false;
                return layer.fill(html, true);
            }
        }).catch(function () { /* keep what is shown */ });
    };

    /* ------------------------------------------------------------------
     * Opening
     * ------------------------------------------------------------------ */
    function push() {
        var layer = new Layer();
        stack.push(layer);
        return layer;
    }

    function openUrl(url, from) {
        var layer = push();
        layer.trigger = from;
        layer.url = url;
        layer.show(from ? from.textContent.trim() : '', '');
        layer.loading();
        layer.busy = true;
        fetch(url, { headers: HEADERS, credentials: 'same-origin' }).then(function (response) {
            layer.busy = false;
            var type = response.headers.get('Content-Type') || '';
            if (type.indexOf('application/json') !== -1) {
                // The page sent the user elsewhere instead of showing a form.
                return response.json().then(function (data) {
                    layer.close(true);
                    if (!data.ok && window.Sweet) {
                        window.Sweet.alert({ type: 'error', title: 'Cannot open the form', messages: [data.error], confirmText: 'OK' });
                    } else if (stack.length) {
                        // Opened from another modal: show its message there instead of leaving the page.
                        refreshAll();
                    } else {
                        location.href = data.redirect;
                    }
                });
            }
            return response.text().then(function (html) { return layer.fill(html); });
        }).catch(function () { layer.failed(); });
    }

    function openPanel(button) {
        var target = (button.closest('.modal-form__body') || document).querySelector('#' + CSS.escape(button.getAttribute('data-modal-panel')))
            || document.getElementById(button.getAttribute('data-modal-panel'));
        if (!target) return;
        var layer = push();
        layer.trigger = button;
        var label = button.querySelector('span');
        layer.show((label || button).textContent.trim(), button.getAttribute('data-modal-subtitle') || '');
        layer.body.innerHTML = '';
        layer.panelHome = document.createComment('modal panel');
        target.parentNode.insertBefore(layer.panelHome, target);
        layer.panel = target;
        layer.body.appendChild(target);
        target.hidden = false;
        if (window.App) window.App.init(layer.body);
        layer.focusFirst();
    }

    document.addEventListener('click', function (event) {
        if (event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        // While a modal is open only its own links open a modal (on top of it).
        var current = top();
        if (current && !current.el.contains(event.target)) return;
        var link = event.target.closest('a[data-modal-form]');
        if (link && link.getAttribute('target')) link = null;
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

    document.addEventListener('keydown', function (event) {
        var current = top();
        if (!current || document.querySelector('.sweet')) return;
        if (event.key === 'Escape') {
            event.preventDefault();
            current.close(false);
        } else if (event.key === 'Tab') {
            trapFocus(current, event);
        }
    });

    function trapFocus(layer, event) {
        var items = Array.prototype.filter.call(
            layer.el.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]):not([type=hidden]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'),
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

    /* ------------------------------------------------------------------
     * Saving
     * ------------------------------------------------------------------ */
    function onSubmit(layer, event) {
        var form = event.target;
        if (event.defaultPrevented || layer.busy) {
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
            layer.loading();
            layer.busy = true;
            layer.url = url.toString();
            fetch(url.toString(), { headers: HEADERS, credentials: 'same-origin' })
                .then(function (response) { layer.busy = false; return response.text(); })
                .then(function (html) { return layer.fill(html); })
                .catch(function () { layer.failed(); });
            return;
        }

        var send = function () {
            var data = new FormData(form);
            if (event.submitter && event.submitter.name) data.append(event.submitter.name, event.submitter.value);
            if (submitter && window.App && window.App.setLoading) window.App.setLoading(submitter);
            layer.busy = true;

            fetch(action, { method: 'POST', body: data, headers: HEADERS, credentials: 'same-origin' }).then(function (response) {
                layer.busy = false;
                var type = response.headers.get('Content-Type') || '';
                if (type.indexOf('application/json') !== -1) {
                    return response.json().then(function (result) {
                        if (submitter && window.App) window.App.resetLoading(submitter);
                        if (!result.ok) {
                            layer.showError(result.error || 'The record was not saved. Please check the form.');
                            return;
                        }
                        if (/\/auth\//.test(result.redirect)) {
                            location.href = result.redirect;   // signed out meanwhile
                            return;
                        }
                        layer.dirty = false;
                        // An action inside a record shown in the modal (a button, an in-place panel, waive /
                        // delete selected ...) keeps that record open and reloads it; only a record form
                        // (Add / Edit, .form-card) closes its modal.
                        var keepOpen = !layer.panel && layer.url && !form.classList.contains('form-card');
                        if (!keepOpen && layer === top()) layer.close(true);
                        refreshAll();
                    });
                }
                // The form came back (validation errors): show it again, keep what was typed.
                return response.text().then(function (html) {
                    if (!response.ok && html.indexOf('<main') === -1) {
                        if (submitter && window.App) window.App.resetLoading(submitter);
                        layer.showError('The record was not saved (error ' + response.status + '). Please try again.');
                        return;
                    }
                    if (layer.panel) {
                        if (submitter && window.App) window.App.resetLoading(submitter);
                        layer.showError('The record was not saved. Please check the form.');
                        return;
                    }
                    return layer.fill(html);
                });
            }).catch(function () {
                if (submitter && window.App) window.App.resetLoading(submitter);
                layer.failed();
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

    // After a save: reload the modal now on top (if any) and the page content behind it.
    function refreshAll() {
        var current = top();
        if (current) current.reload();
        refreshPage();
    }

    // Reload only the page content (list, totals, related tables) in place.
    function refreshPage() {
        fetch(location.href, { credentials: 'same-origin' }).then(function (response) {
            return response.text();
        }).then(function (html) {
            var fresh = parse(html).querySelector('main');
            var main = document.querySelector('main');
            if (!fresh || !main) {
                if (!stack.length) location.reload();
                return;
            }
            main.innerHTML = fresh.innerHTML;
            if (window.App) window.App.init(main);
            if (window.Sweet && window.Sweet.showFlashes) window.Sweet.showFlashes(main);
        }).catch(function () {
            if (!stack.length) location.reload();
        });
    }
})();
