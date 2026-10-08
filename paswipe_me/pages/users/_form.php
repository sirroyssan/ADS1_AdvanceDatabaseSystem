<?php
/**
 * Shared create/edit form for users.
 * Expects: $data, $errors, $roles, $formAction, $submitLabel, $cancelUrl, $isNew
 */

defined('APP_ROOT') or exit('No direct access.');
?>
<form method="post" action="<?= e($formAction) ?>" class="form-card form-card--wide" novalidate data-validate>
    <?= csrf_field() ?>

    <?php if ($errors): ?>
        <div class="alert alert--error" role="alert"><?= icon('alert') ?>
            <span><?= e($errors['_form'] ?? 'Please correct the highlighted fields below.') ?></span>
        </div>
    <?php endif; ?>

    <?= render_fields(user_fields(), $data, $errors) ?>

    <h3 class="form-section">Roles</h3>
    <div class="form-grid"><?= render_role_checkboxes($roles, $errors['roles'] ?? null) ?></div>

    <?= render_password_fields($errors, $isNew) ?>

    <div class="form-actions">
        <a href="<?= e($cancelUrl) ?>" class="btn btn--outline">Cancel</a>
        <button type="submit" class="btn btn--primary" data-loading-text="Saving…"><?= icon('check') ?> <span class="btn__label"><?= e($submitLabel) ?></span></button>
    </div>
</form>
