<?php
/**
 * Request authorization to edit or delete (void) a confirmed record (staff).
 * Shows the record, the action, the reason, the corrected values (edit) and an
 * optional owner sign-in so the owner can approve on the spot. Everything is
 * checked on the server (includes/authorizations.php); without a valid
 * approval nothing changes and the request waits for the owner.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once APP_ROOT . '/includes/authorizations.php';

$currentUser = require_staff_area();

$type = input_string($_GET, 'type') ?: input_string($_POST, 'type');
$id = request_id();
$action = input_string($_GET, 'action') ?: input_string($_POST, 'action');
$back = $type === 'transaction' ? 'pages/transactions/view.php?id=' . $id : 'pages/staff/receipts.php';
if (!isset(AUTH_RECORD_TYPES[$type]) || !isset(AUTH_ACTIONS[$action])) {
    flash('error', 'Unknown request.');
    redirect('pages/staff/index.php');
}
$r = find_or_redirect(auth_record($type, $id), 'pages/staff/index.php', AUTH_RECORD_TYPES[$type]);
if (user_is_admin($currentUser)) {
    // The owner changes their own records directly; requests are for staff.
    flash('info', 'You are the owner: use Edit / Override on the record itself.');
    redirect($type === 'transaction' ? 'pages/transactions/view.php' : 'pages/borrower_payments/view.php', ['id' => $id]);
}
$pending = db_value("SELECT request_number FROM authorization_requests WHERE record_type = :t AND record_id = :r AND status = 'pending'", [':t' => $type, ':r' => $id]);

$fields = $r['fields'];
$data = form_data_from_row($fields, $r['record']);
$errors = [];
$reason = '';
$problem = $action === 'delete' ? auth_delete_problem($type, $r) : null;

if (is_post()) {
    require_csrf('pages/authorizations/request.php?type=' . $type . '&id=' . $id . '&action=' . $action);
    $reason = trim(input_string($_POST, 'reason'));
    if (mb_strlen($reason) < AUTH_REASON_MIN) {
        $errors['reason'] = 'Explain the change in at least ' . AUTH_REASON_MIN . ' characters.';
    }
    $changes = null;
    if ($action === 'edit') {
        [$changes, $editErrors] = auth_edit_changes($type, $r, $_POST);
        $errors += $editErrors;
        [$data] = validate_fields($fields, $_POST);
    } elseif ($problem) {
        $errors['_form'] = $problem;
    }
    if ($pending) {
        $errors['_form'] = 'Request ' . $pending . ' for this record is already waiting for the owner.';
    }
    $login = trim(input_string($_POST, 'owner_login'));
    $password = is_string($_POST['owner_password'] ?? null) ? $_POST['owner_password'] : '';
    if (!$errors && !form_once_use('auth_request', input_string($_POST, 'once'))) {
        flash('info', 'This request was already sent.');
        redirect('pages/authorizations/index.php');
    }
    if (!$errors) {
        $requestId = auth_create($type, $r, $action, $reason, $changes, $currentUser);
        if ($login !== '' || $password !== '') {
            [$approver, $authError] = auth_verify_approver($login, $password, (string) $r['owner'], $currentUser['id']);
            if ($approver === null) {
                flash('error', $authError . ' The request was saved and waits for the owner.');
            } elseif ($error = auth_approve($requestId, $approver, 'credentials')) {
                flash('error', 'Authorized, but the change could not be applied: ' . $error);
            } else {
                flash('success', 'Authorized by ' . user_name_of($approver['id']) . ' and applied. ' . db_value('SELECT result FROM authorization_requests WHERE id = :id', [':id' => $requestId]));
            }
        } else {
            notify_user((string) $r['owner'], 'authorization_requested', 'Approval needed: ' . AUTH_ACTIONS[$action] . ' ' . $r['ref'],
                user_name_of($currentUser['id']) . ' asks to ' . $action . ' ' . $r['ref'] . '. Reason: ' . $reason, 'authorization_request', $requestId);
            flash('success', 'Request sent to the card owner. Nothing changes until they approve it.');
        }
        redirect('pages/authorizations/view.php', ['id' => $requestId]);
    }
}

$pageTitle = 'Request Authorization';
$activeNav = 'authorizations';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb(['Change requests' => url('pages/authorizations/index.php'), $r['ref'] => null]) ?>

<section class="page-header">
    <div>
        <h2 class="page-header__title"><?= e(AUTH_ACTIONS[$action]) ?> <?= e($r['ref']) ?></h2>
        <p class="page-header__subtitle"><?= e(AUTH_MESSAGE) ?></p>
    </div>
</section>

<form method="post" action="<?= e(url('pages/authorizations/request.php', ['type' => $type, 'id' => $id, 'action' => $action])) ?>" class="form-card form-card--wide" novalidate data-validate>
    <?= csrf_field() ?>
    <?= form_once_field('auth_request') ?>
    <div class="alert alert--warning" role="note"><?= icon('lock') ?><span><?= e(AUTH_MESSAGE) ?></span></div>
    <?php if ($errors || $problem || $pending): ?>
        <div class="alert alert--error" role="alert"><?= icon('alert') ?><span><?= e($errors['_form'] ?? ($pending ? 'Request ' . $pending . ' for this record is already waiting for the owner.' : ($problem ?? 'Please correct the highlighted fields below.'))) ?></span></div>
    <?php endif; ?>

    <h3 class="form-section"><?= e(AUTH_RECORD_TYPES[$type]) ?></h3>
    <?= render_details($r['details'] + ['Requested action' => '<strong>' . e(AUTH_ACTIONS[$action]) . '</strong>' . ($action === 'delete' ? ' <span class="text-muted">(the record is voided and kept for the audit trail, never erased)</span>' : '')]) ?>

    <?php if ($action === 'edit'): ?>
        <h3 class="form-section">Corrected values</h3>
        <?= render_fields($fields, $data, $errors) ?>
    <?php endif; ?>

    <?= render_fields(['reason' => ['label' => 'Reason for the change', 'type' => 'textarea', 'rows' => 3, 'required' => true, 'max' => 1000,
        'help' => 'At least ' . AUTH_REASON_MIN . ' characters. The owner sees it, and it is kept in the audit log.']], ['reason' => $reason], $errors) ?>

    <h3 class="form-section">Administrator authorization</h3>
    <p class="field-help">If the card owner is with you, they can approve now with their own sign-in. Otherwise leave these empty: the request waits in the owner's Authorization Requests. The password is checked on the server and never saved.</p>
    <?= render_fields([
        'owner_login'    => ['label' => 'Owner email or username', 'type' => 'text', 'max' => 255, 'attrs' => ['autocomplete' => 'off']],
        'owner_password' => ['label' => 'Owner password', 'type' => 'password', 'max' => 255, 'attrs' => ['autocomplete' => 'new-password']],
    ], [], []) ?>

    <div class="form-actions">
        <a href="<?= e(url($back)) ?>" class="btn btn--outline">Cancel</a>
        <button type="submit" class="btn btn--primary" data-loading-text="Sending…" <?= $problem || $pending ? 'disabled' : '' ?>><?= icon('shield') ?> <span class="btn__label">Request authorization</span></button>
    </div>
</form>

<?php require APP_ROOT . '/includes/footer.php'; ?>
