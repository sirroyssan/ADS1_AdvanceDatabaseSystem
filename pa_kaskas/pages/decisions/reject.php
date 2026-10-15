<?php
/**
 * The reason for a rejection, in its own modal (opened by the Reject button of
 * a decision bar - includes/decisions.php). It only collects the reason: the
 * form posts to the module's own handler, which checks the role, ownership
 * and status again and records the rejection.
 */

require_once __DIR__ . '/../../includes/auth_check.php';

$currentUser = require_admin();

$kind = input_string($_GET, 'kind');
$def = DECISION_KINDS[$kind] ?? null;
$ids = [];
foreach ($def['ids'] ?? [] as $key) {
    $ids[$key] = input_string($_GET, $key);
}
if ($def === null || in_array(false, array_map('is_valid_uuid', $ids), true)) {
    flash('error', 'Nothing to reject.');
    redirect('pages/dashboard.php');
}

// What is being rejected, from the owner-scoped finders (nothing of another owner is named).
$subject = null;
switch ($kind) {
    case 'repayment':
    case 'payment':
        require_once APP_ROOT . '/pages/borrower_payments/payment_functions.php';
        $p = payment_find($ids['payment_id'] ?? $ids['id']);
        $subject = $p ? $p['payment_number'] . ' · ' . format_money($p['amount']) . ' · ' . borrower_name($p) : null;
        break;
    case 'authorization':
        require_once APP_ROOT . '/includes/authorizations.php';
        $r = auth_find($ids['id']);
        $subject = $r ? $r['request_number'] . ' · ' . humanize($r['action']) . ' ' . ($r['record_ref'] ?? '') : null;
        break;
    case 'account':
        require_system_admin();
        $u = db_row('SELECT email, first_name, last_name, username FROM users WHERE id = :id', [':id' => $ids['id']]);
        $subject = $u ? user_display_label($u) : null;
        break;
    case 'reset':
        require_system_admin();
        password_reset_upgrade();
        $r = db_row('SELECT p.request_number, u.email, u.first_name, u.last_name, u.username FROM password_reset_requests p JOIN users u ON u.id = p.user_id WHERE p.id = :id', [':id' => $ids['id']]);
        $subject = $r ? $r['request_number'] . ' · ' . user_display_label($r) : null;
        break;
}
if ($subject === null) {
    flash('error', 'Not found, or not yours to decide.');
    redirect('pages/dashboard.php');
}

$pageTitle = $def['title'];
$activeNav = '';
require APP_ROOT . '/includes/header.php';
?>

<section class="page-header">
    <div>
        <h2 class="page-header__title"><?= e($def['title']) ?></h2>
        <p class="page-header__subtitle"><?= e($subject) ?></p>
    </div>
</section>

<form method="post" action="<?= e(url($def['handler'])) ?>" class="form-card" novalidate data-validate>
    <?= csrf_field() ?>
    <?php foreach ($ids + $def['fixed'] as $name => $value): ?>
        <input type="hidden" name="<?= e($name) ?>" value="<?= e($value) ?>">
    <?php endforeach; ?>
    <div class="form-group">
        <label for="f_reject_reason">Reason for rejecting <span class="required" aria-hidden="true">*</span></label>
        <textarea id="f_reject_reason" name="<?= e($def['reason']) ?>" rows="4" maxlength="1000" required minlength="3"
                  placeholder="e.g. The amount did not arrive in my account" autofocus></textarea>
        <p class="field-help"><?= e($def['help']) ?></p>
    </div>
    <div class="form-actions">
        <a href="<?= e(url('pages/dashboard.php')) ?>" class="btn btn--outline" data-modal-close>Cancel</a>
        <button type="submit" class="btn btn--danger" data-loading-text="Saving…"><?= icon('x') ?> <span class="btn__label">Reject</span></button>
    </div>
</form>

<?php require APP_ROOT . '/includes/footer.php'; ?>
