/**
 * Categories module: form validation feedback, character counter and
 * search shortcuts. Delete confirmation, loading states and printing are
 * handled by dashboard.js for every module.
 */
(function () {
    'use strict';

    /* ------------------------------------------------------------------
     * Create / edit form
     * ------------------------------------------------------------------ */
    // Also runs when the form is opened in a modal (assets/js/modal-form.js).
    window.App.register(function (scope) {
    var form = scope.querySelector('#category-form');

    if (form && window.App.once(form, 'Category')) {
        var name = form.querySelector('#name');
        var nameError = form.querySelector('#name-error');
        var counter = form.querySelector('#' + name.getAttribute('data-counter'));
        var maxLength = parseInt(name.getAttribute('maxlength'), 10) || 150;

        function updateCounter() {
            var length = name.value.length;
            counter.textContent = length + ' / ' + maxLength;
            counter.classList.toggle('is-near-limit', length >= maxLength - 10);
        }

        function showNameError(message) {
            nameError.textContent = message;
            nameError.hidden = !message;
            name.classList.toggle('is-invalid', Boolean(message));
            name.setAttribute('aria-invalid', message ? 'true' : 'false');
        }

        function validateName() {
            var value = name.value.trim();
            if (value === '') {
                showNameError('Category name is required.');
                return false;
            }
            if (value.length > maxLength) {
                showNameError('Category name must not exceed ' + maxLength + ' characters.');
                return false;
            }
            showNameError('');
            return true;
        }

        if (counter) {
            updateCounter();
            name.addEventListener('input', updateCounter);
        }

        name.addEventListener('blur', validateName);
        name.addEventListener('input', function () {
            if (name.classList.contains('is-invalid')) validateName();
        });

        // Runs before dashboard.js's loading-state handler (capture phase).
        form.addEventListener('submit', function (event) {
            if (!validateName()) {
                event.preventDefault();
                event.stopImmediatePropagation();
                name.focus();
            }
        }, true);
    }

    /* ------------------------------------------------------------------
     * Search box: Escape clears it
     * ------------------------------------------------------------------ */
    var search = scope.querySelector('#search');

    if (search && window.App.once(search, 'CategorySearch')) {
        search.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && search.value !== '') {
                search.value = '';
            }
        });
    }
    });
})();
