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
    document.querySelectorAll('[data-dropdown]').forEach(function (dropdown) {
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
    });

    /* ------------------------------------------------------------------
     * Notifications: dismiss button, success messages fade after 6s
     * ------------------------------------------------------------------ */
    function dismissAlert(alert) {
        alert.classList.add('is-hiding');
        setTimeout(function () {
            alert.remove();
        }, 260);
    }

    document.querySelectorAll('[data-alert]').forEach(function (alert) {
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
    });

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
    var modal = document.getElementById('confirm-modal');
    var modalMessage = document.getElementById('confirm-message');
    var modalAccept = document.getElementById('confirm-accept');
    var pendingForm = null;
    var lastFocus = null;

    function openModal(form) {
        pendingForm = form;
        lastFocus = document.activeElement;
        modalMessage.textContent = form.getAttribute('data-confirm');
        // Optional wording for non-delete confirmations (cancel, approve, ...).
        document.getElementById('confirm-title').textContent = form.getAttribute('data-confirm-title') || 'Delete record?';
        modalAccept.querySelector('.btn__label').textContent = form.getAttribute('data-confirm-button') || 'Delete';
        modal.hidden = false;
        body.classList.add('modal-open');
        modal.querySelector('[data-modal-close].btn').focus();
    }

    function closeModal() {
        modal.hidden = true;
        body.classList.remove('modal-open');
        resetLoading(modalAccept);
        pendingForm = null;
        if (lastFocus) lastFocus.focus();
    }

    if (modal) {
        document.addEventListener('submit', function (event) {
            var form = event.target;
            if (!form.hasAttribute('data-confirm') || form.dataset.confirmed === '1') return;
            event.preventDefault();
            openModal(form);
        });

        modal.querySelectorAll('[data-modal-close]').forEach(function (el) {
            el.addEventListener('click', closeModal);
        });

        modalAccept.addEventListener('click', function () {
            if (!pendingForm) return;
            setLoading(modalAccept);
            pendingForm.dataset.confirmed = '1';
            pendingForm.submit();
        });

        modal.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                closeModal();
            }
            // Keep keyboard focus inside the dialog.
            if (event.key === 'Tab') {
                var focusable = modal.querySelectorAll('button:not([disabled])');
                var first = focusable[0];
                var last = focusable[focusable.length - 1];
                if (event.shiftKey && document.activeElement === first) {
                    event.preventDefault();
                    last.focus();
                } else if (!event.shiftKey && document.activeElement === last) {
                    event.preventDefault();
                    first.focus();
                }
            }
        });
    }

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
    document.querySelectorAll('[data-print]').forEach(function (button) {
        button.addEventListener('click', function () {
            window.print();
        });
    });

    // Print preview pages open the print dialog automatically once.
    if (document.querySelector('[data-auto-print]')) {
        window.addEventListener('load', function () {
            setTimeout(function () { window.print(); }, 300);
        });
    }

    /* ------------------------------------------------------------------
     * Filters that apply as soon as they change
     * ------------------------------------------------------------------ */
    document.querySelectorAll('select[data-auto-submit]').forEach(function (select) {
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
    });
})();
