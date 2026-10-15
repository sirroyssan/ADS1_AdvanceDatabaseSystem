<?php
/**
 * Shared create/edit form for categories.
 *
 * Expects: $data, $errors, $parentOptions, $formAction, $submitLabel, $cancelUrl
 */

defined('APP_ROOT') or exit('No direct access.');
?>
<form method="post" action="<?= e($formAction) ?>" class="form-card" id="category-form" novalidate>
    <?= csrf_field() ?>

    <?php if ($errors): ?>
        <div class="alert alert--error" role="alert">
            <?= icon('alert') ?>
            <span>Please correct the highlighted field<?= count($errors) > 1 ? 's' : '' ?> below.</span>
        </div>
    <?php endif; ?>

    <div class="form-grid">
        <div class="form-group form-group--full">
            <label for="name">Category name <span class="required" aria-hidden="true">*</span></label>
            <input type="text" id="name" name="name" maxlength="<?= CATEGORY_NAME_MAX ?>" required
                   value="<?= e($data['name']) ?>"
                   class="<?= isset($errors['name']) ? 'is-invalid' : '' ?>"
                   aria-invalid="<?= isset($errors['name']) ? 'true' : 'false' ?>"
                   aria-describedby="name-help name-error" data-counter="name-count">
            <p class="field-help" id="name-help">
                Must be unique within the same parent.
                <span class="char-count" id="name-count" aria-live="polite"></span>
            </p>
            <p class="field-error" id="name-error" <?= isset($errors['name']) ? '' : 'hidden' ?>><?= e($errors['name'] ?? '') ?></p>
        </div>

        <div class="form-group form-group--full">
            <label for="parent_id">Parent category</label>
            <select id="parent_id" name="parent_id"
                    class="<?= isset($errors['parent_id']) ? 'is-invalid' : '' ?>"
                    aria-invalid="<?= isset($errors['parent_id']) ? 'true' : 'false' ?>"
                    aria-describedby="parent-help parent-error">
                <option value="">— None (top-level category) —</option>
                <?php foreach ($parentOptions as $optionId => $optionLabel): ?>
                    <option value="<?= e($optionId) ?>" <?= $optionId === $data['parent_id'] ? 'selected' : '' ?>>
                        <?= e($optionLabel) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="field-help" id="parent-help">Leave empty to create a main category.</p>
            <p class="field-error" id="parent-error" <?= isset($errors['parent_id']) ? '' : 'hidden' ?>><?= e($errors['parent_id'] ?? '') ?></p>
        </div>

        <div class="form-group form-group--full">
            <label for="description">Description</label>
            <textarea id="description" name="description" rows="4"
                      class="<?= isset($errors['description']) ? 'is-invalid' : '' ?>"
                      aria-describedby="description-error"><?= e($data['description'] ?? '') ?></textarea>
            <p class="field-error" id="description-error" <?= isset($errors['description']) ? '' : 'hidden' ?>><?= e($errors['description'] ?? '') ?></p>
        </div>

        <div class="form-group form-group--full">
            <span class="label">Status</span>
            <label class="switch">
                <input type="checkbox" name="is_active" value="1" <?= (int) $data['is_active'] === 1 ? 'checked' : '' ?>>
                <span class="switch__track" aria-hidden="true"></span>
                <span class="switch__label">Active (available for merchants and transactions)</span>
            </label>
        </div>
    </div>

    <div class="form-actions">
        <a href="<?= e($cancelUrl) ?>" class="btn btn--outline">Cancel</a>
        <button type="submit" class="btn btn--primary" data-loading-text="Saving…">
            <?= icon('check') ?> <span class="btn__label"><?= e($submitLabel) ?></span>
        </button>
    </div>
</form>
