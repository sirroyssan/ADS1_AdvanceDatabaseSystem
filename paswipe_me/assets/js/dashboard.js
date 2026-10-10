/**
 * Shared dashboard behaviour (loaded on every dashboard page by footer.php):
 * sidebar drawer & collapse, profile dropdown, notifications, confirmation
 * modal, loading states, print buttons and auto-submitting filters.
 */
(function () {
    'use strict';

    var root = document.documentElement;
    var body = document.body;

    /* ------------------------------------------------------------------
     * Content set-up that must also run on content added later (a modal
     * form, or a list refreshed after saving): App.register(fn) runs fn on
     * the page now and on every App.init(scope) call. App.once() keeps an
     * element from being set up twice.
     * ------------------------------------------------------------------ */
    var inits = [];
    window.App = {
        register: function (fn) {
            inits.push(fn);
            fn(document);
        },
        init: function (scope) {
            inits.forEach(function (fn) { fn(scope || document); });
        },
        once: function (el, key) {
            var name = 'bound' + key;
            if (el.dataset[name]) return false;
            el.dataset[name] = '1';
            return true;
        }
    };

    /* ------------------------------------------------------------------
     * Sidebar: off-canvas drawer on mobile, collapse on desktop
     * ------------------------------------------------------------------ */
    var sidebar = document.getElementById('sidebar');
    var backdrop = document.querySelector('.sidebar-backdrop');
    var menuButton = document.querySelector('[data-sidebar-toggle]');
    var collapseButton = document.querySelector('[data-sidebar-collapse]');

    function openSidebar() {
        root.classList.add('sidebar-open');
        if (backdrop) backdrop.hidden = false;
        if (menuButton) menuButton.setAttribute('aria-expanded', 'true');
        var firstLink = sidebar && sidebar.querySelector('a.nav-link');
        if (firstLink) firstLink.focus();
    }

    function closeSidebar() {
        root.classList.remove('sidebar-open');
        if (backdrop) backdrop.hidden = true;
        if (menuButton) menuButton.setAttribute('aria-expanded', 'false');
    }

    if (menuButton) {
        menuButton.addEventListener('click', function () {
            root.classList.contains('sidebar-open') ? closeSidebar() : openSidebar();
        });
    }

    document.querySelectorAll('[data-sidebar-close]').forEach(function (el) {
        el.addEventListener('click', closeSidebar);
    });

    // Close the drawer when resizing up to desktop.
    window.addEventListener('resize', function () {
        if (window.innerWidth > 991 && root.classList.contains('sidebar-open')) {
            closeSidebar();
        }
    });

    function syncCollapseButton() {
        if (!collapseButton) return;
        var collapsed = root.classList.contains('sidebar-collapsed');
        collapseButton.setAttribute('aria-pressed', collapsed ? 'true' : 'false');
        collapseButton.setAttribute('aria-label', collapsed ? 'Expand sidebar' : 'Collapse sidebar');
    }

    if (collapseButton) {
        syncCollapseButton();
        collapseButton.addEventListener('click', function () {
            root.classList.toggle('sidebar-collapsed');
            try {
                localStorage.setItem('sidebar-collapsed', root.classList.contains('sidebar-collapsed') ? '1' : '0');
            } catch (e) { /* storage may be blocked; collapse still works for this page */ }
            syncCollapseButton();
        });
    }

    /* ------------------------------------------------------------------
     * Profile dropdown
     * ------------------------------------------------------------------ */
    App.register(function (scope) { scope.querySelectorAll('[data-dropdown]').forEach(function (dropdown) {
        if (!App.once(dropdown, 'Dropdown')) return;
        var toggle = dropdown.querySelector('[data-dropdown-toggle]');
        var menu = dropdown.querySelector('[data-dropdown-menu]');

        function close() {
            menu.hidden = true;
            toggle.setAttribute('aria-expanded', 'false');
        }

        toggle.addEventListener('click', function (event) {
            event.stopPropagation();
            var open = menu.hidden;
            menu.hidden = !open;
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        });

        document.addEventListener('click', function (event) {
            if (!dropdown.contains(event.target)) close();
        });

        dropdown.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                close();
                toggle.focus();
            }
        });
    }); });

    /* ------------------------------------------------------------------
     * Notifications: dismiss button, success messages fade after 6s
     * ------------------------------------------------------------------ */
    function dismissAlert(alert) {
        alert.classList.add('is-hiding');
        setTimeout(function () {
            alert.remove();
        }, 260);
    }

    App.register(function (scope) { scope.querySelectorAll('[data-alert]').forEach(function (alert) {
        if (!App.once(alert, 'Alert')) return;
        var close = alert.querySelector('[data-alert-close]');
        if (close) {
            close.addEventListener('click', function () {
                dismissAlert(alert);
            });
        }
        if (alert.classList.contains('alert--success')) {
            setTimeout(function () {
                if (document.body.contains(alert)) dismissAlert(alert);
            }, 6000);
        }
    }); });

    /* ------------------------------------------------------------------
     * Loading state for submit buttons with data-loading-text
     * ------------------------------------------------------------------ */
    function setLoading(button) {
        if (!button || button.classList.contains('is-loading')) return;
        var label = button.querySelector('.btn__label');
        button.dataset.originalHtml = button.innerHTML;
        button.classList.add('is-loading');
        button.setAttribute('aria-busy', 'true');
        button.disabled = true;
        button.innerHTML = '<span class="spinner" aria-hidden="true"></span><span class="btn__label"></span>';
        button.querySelector('.btn__label').textContent =
            button.getAttribute('data-loading-text') || (label ? label.textContent : 'Please wait…');
    }

    function resetLoading(button) {
        if (!button || !button.dataset.originalHtml) return;
        button.innerHTML = button.dataset.originalHtml;
        button.classList.remove('is-loading');
        button.removeAttribute('aria-busy');
        button.disabled = false;
        delete button.dataset.originalHtml;
    }

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (event.defaultPrevented || form.hasAttribute('data-confirm')) return;
        var button = form.querySelector('button[type="submit"][data-loading-text]');
        if (button) {
            // Let the browser collect form data first, then disable the button.
            setTimeout(function () { setLoading(button); }, 0);
        }
    });

    // Restore buttons when the page is shown again from the back/forward cache.
    window.addEventListener('pageshow', function (event) {
        if (event.persisted) {
            document.querySelectorAll('.is-loading').forEach(resetLoading);
        }
    });

    /* ------------------------------------------------------------------
     * Confirmation modal: any <form data-confirm="message">
     * ------------------------------------------------------------------ */
    // Shown with Sweet.confirm (assets/js/sweet.js). Delete-style confirmations get
    // the red trash icon; others (cancel, approve, change status...) a question icon.
    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!form.hasAttribute('data-confirm') || form.dataset.confirmed === '1' || !window.Sweet) return;
        event.preventDefault();

        var button = form.getAttribute('data-confirm-button') || 'Delete';
        var isDelete = /delete|remove/i.test(button) || !form.hasAttribute('data-confirm-title');
        window.Sweet.confirm({
            type: isDelete ? 'danger' : 'question',
            title: form.getAttribute('data-confirm-title') || 'Delete record?',
            text: form.getAttribute('data-confirm'),
            confirmText: button,
            cancelText: 'Cancel'
        }).then(function (accepted) {
            if (!accepted) return;
            form.dataset.confirmed = '1';
            var submit = form.querySelector('button[type="submit"]');
            if (submit && submit.hasAttribute('data-loading-text')) setLoading(submit);
            form.submit();
        });
    });

    /* ------------------------------------------------------------------
     * Escape closes the mobile drawer
     * ------------------------------------------------------------------ */
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && root.classList.contains('sidebar-open')) {
            closeSidebar();
            if (menuButton) menuButton.focus();
        }
    });

    /* ------------------------------------------------------------------
     * Print
     * ------------------------------------------------------------------ */
    App.register(function (scope) { scope.querySelectorAll('[data-print]').forEach(function (button) {
        if (!App.once(button, 'Print')) return;
        button.addEventListener('click', function () {
            window.print();
        });
    }); });

    // Print preview pages open the print dialog automatically once.
    if (document.querySelector('[data-auto-print]')) {
        window.addEventListener('load', function () {
            setTimeout(function () { window.print(); }, 300);
        });
    }

    /* ------------------------------------------------------------------
     * Filters that apply as soon as they change
     * ------------------------------------------------------------------ */
    App.register(function (scope) { scope.querySelectorAll('select[data-auto-submit]').forEach(function (select) {
        if (!App.once(select, 'AutoSubmit')) return;
        select.addEventListener('change', function () {
            var form = select.form;
            if (!form) return;
            // Changing a filter starts again from page 1.
            var page = form.querySelector('input[name="page"]');
            if (page) page.remove();
            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit();
            } else {
                form.submit();
            }
        });
    }); });

    window.App.setLoading = setLoading;
    window.App.resetLoading = resetLoading;
})();
