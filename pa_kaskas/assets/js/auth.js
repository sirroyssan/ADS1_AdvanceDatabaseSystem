/**
 * Login page: show/hide password, client-side validation, loading state.
 * The server validates everything again; this is only for quick feedback.
 */
(function () {
    'use strict';

    var form = document.getElementById('login-form');
    if (!form) {
        return;
    }

    var identifier = document.getElementById('identifier');
    var password = document.getElementById('password');
    var toggle = document.getElementById('toggle-password');
    var button = document.getElementById('login-button');

    // Show / hide password
    if (toggle) {
        toggle.addEventListener('click', function () {
            var show = password.type === 'password';
            password.type = show ? 'text' : 'password';
            toggle.setAttribute('aria-pressed', show ? 'true' : 'false');
            toggle.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
            password.focus();
        });
    }

    function setError(input, message) {
        var error = document.getElementById(input.id + '-error');
        input.classList.toggle('is-invalid', Boolean(message));
        input.setAttribute('aria-invalid', message ? 'true' : 'false');
        if (error) {
            error.textContent = message;
            error.hidden = !message;
        }
    }

    [identifier, password].forEach(function (input) {
        input.addEventListener('input', function () {
            setError(input, '');
        });
    });

    form.addEventListener('submit', function (event) {
        var valid = true;

        if (identifier.value.trim() === '') {
            setError(identifier, 'Please enter your username or email.');
            valid = false;
        }
        if (password.value === '') {
            setError(password, 'Please enter your password.');
            valid = false;
        }

        if (!valid) {
            event.preventDefault();
            (identifier.value.trim() === '' ? identifier : password).focus();
            return;
        }

        // Loading state: prevent double submission
        button.disabled = true;
        button.classList.add('is-loading');
        button.innerHTML = '<span class="spinner" aria-hidden="true"></span><span class="btn__label"></span>';
        button.querySelector('.btn__label').textContent = button.getAttribute('data-loading-text') || 'Signing in…';
    });
})();
