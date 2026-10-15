<?php
/**
 * Obligations - change the status (opened in a modal from the obligation page).
 * The form posts to related.php (action=status), which checks the change again.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/obligation_functions.php';

$currentUser = require_admin();

$obligation = find_or_redirect(obligation_find(request_id()), 'pages/obligations/index.php', 'Obligation');
$choices = obligation_status_choices($obligation);
if (!$choices) {
    flash('error', 'The status of a ' . humanize($obligation['status']) . ' obligation cannot be changed by hand.');
    redirect('pages/obligations/view.php', ['id' => $obligation['id']]);
}

$pageTitle = 'Change Status';
$activeNav = 'obligations';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb([
    'Obligations' => url('pages/obligations/index.php'),
    $obligation['obligation_number'] => url('pages/obligations/view.php', ['id' => $obligation['id']]),
    'Change status' => null,
]) ?>

<section class="page-header">
    <div>
        <h2 class="page-header__title">Change status</h2>
        <p class="page-header__subtitle"><?= e($obligation['obligation_number']) ?> · <?= e(borrower_name($obligation)) ?> · now <?= badge($obligation['status']) ?></p>
    </div>
</section>

<form method="post" action="<?= e(url('pages/obligations/related.php')) ?>" class="form-card"
          data-confirm="Change the status of <?= e($obligation['obligation_number']) ?>?" data-confirm-title="Change status?" data-confirm-button="Change status">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= e($obligation['id']) ?>">
        <input type="hidden" name="action" value="status">
        <div class="form-grid">
            <div class="form-group form-group--full">
                <label for="obl_status">New status <span class="required" aria-hidden="true">*</span></label>
                <select id="obl_status" name="status" required>
                    <?php foreach ($choices as $s): ?><option value="<?= e($s) ?>"><?= e(humanize($s)) ?></option><?php endforeach; ?>
                </select>
                <p class="field-help">Overdue and paid follow the due dates and payments automatically. Cancel is only possible before any payment; obligations are never deleted.</p>
            </div>
        </div>
        <div class="form-actions">
            <a href="<?= e(url('pages/obligations/view.php', ['id' => $obligation['id']])) ?>" class="btn btn--outline">Cancel</a>
            <button type="submit" class="btn btn--primary"><?= icon('check') ?> <span class="btn__label">Apply</span></button>
        </div>
</form>

<?php require APP_ROOT . '/includes/footer.php'; ?>
