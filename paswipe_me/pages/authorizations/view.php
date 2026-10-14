<?php
/**
 * One authorization request: the record, the action, original and proposed
 * values, reason, decision and result. The owner approves or rejects here.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once APP_ROOT . '/includes/authorizations.php';

$currentUser = require_staff_area();
$req = find_or_redirect(auth_find(request_id()), 'pages/authorizations/index.php', 'Request');
if (user_is_staff($currentUser) && $req['requested_by'] !== $currentUser['id']) {
    flash('error', 'Request not found.');
    redirect('pages/authorizations/index.php');
}
$original = json_decode((string) $req['original_values'], true) ?: [];
$proposed = json_decode((string) $req['proposed_values'], true) ?: [];
$canDecide = $req['status'] === 'pending' && user_is_admin($currentUser) && $req['requested_by'] !== $currentUser['id'];
$recordUrl = $req['record_type'] === 'transaction'
    ? url('pages/transactions/view.php', ['id' => $req['record_id']])
    : (user_is_admin($currentUser) ? url('pages/borrower_payments/view.php', ['id' => $req['record_id']]) : null);

$pageTitle = 'Request ' . $req['request_number'];
$activeNav = 'authorizations';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb(['Authorization Requests' => url('pages/authorizations/index.php'), $req['request_number'] => null]) ?>

<section class="page-header">
    <div>
        <h2 class="page-header__title"><?= e(AUTH_ACTIONS[$req['action']] ?? $req['action']) ?> <?= e($req['record_ref']) ?></h2>
        <p class="page-header__subtitle"><?= e($req['request_number']) ?> · requested by <?= e(user_name_of($req['requested_by'])) ?> · <?= e(format_datetime($req['created_at'])) ?> · <?= auth_status_badge($req['status']) ?></p>
    </div>
</section>

<div class="detail-layout">
    <section class="panel">
        <header class="panel__header"><h3>Request</h3></header>
        <?= render_details([
            'Record'       => e(AUTH_RECORD_TYPES[$req['record_type']] ?? $req['record_type']) . ' ' . ($recordUrl ? '<a href="' . e($recordUrl) . '">' . e($req['record_ref']) . '</a>' : e($req['record_ref'])),
            'Action'       => '<strong>' . e(AUTH_ACTIONS[$req['action']] ?? $req['action']) . '</strong>',
            'Reason'       => show_multiline($req['reason']),
            'Requested by' => e(user_name_of($req['requested_by'])) . ' · ' . e(format_datetime($req['created_at'])),
            'Status'       => auth_status_badge($req['status']),
            'Decided by'   => $req['decided_by'] ? e(user_name_of($req['decided_by'])) . ' · ' . e(format_datetime($req['decided_at'])) . ($req['approval_method'] === 'credentials' ? ' <span class="text-muted">(owner sign-in at the request)</span>' : '') : '—',
            'Decision note' => show($req['decision_note']),
            'Result'       => show($req['result']),
        ]) ?>
        <h4 class="form-section"><?= $req['action'] === 'edit' ? 'Values' : 'Record at the time of the request' ?></h4>
        <div class="table-wrap">
            <table class="table table--cards table--compact">
                <thead><tr><th scope="col">Field</th><th scope="col">Original</th><?= $req['action'] === 'edit' ? '<th scope="col">Requested</th>' : '' ?></tr></thead>
                <tbody>
                <?php foreach ($original as $k => $v): ?>
                    <tr<?= array_key_exists($k, $proposed) ? ' class="is-current"' : '' ?>><td data-label="Field"><?= e(humanize($k)) ?></td><td data-label="Original"><?= show($v) ?></td>
                        <?= $req['action'] === 'edit' ? '<td data-label="Requested">' . (array_key_exists($k, $proposed) ? '<strong>' . show($proposed[$k]) . '</strong>' : '<span class="text-muted">unchanged</span>') . '</td>' : '' ?></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <div class="detail-side">
        <?php if ($canDecide): ?>
            <section class="panel no-print">
                <header class="panel__header"><h3>Your decision</h3></header>
                <p class="field-help">Approving applies the change at once to the record and every balance that depends on it. A delete voids the record; it is never erased.</p>
                <?= decision_bar([url('pages/authorizations/decide.php'), ['id' => $req['id'], 'decision' => 'approve'],
                    'Approve and apply this change now? Balances that depend on it are updated at once.', 'Approve request?'],
                    'authorization', ['id' => $req['id']]) ?>
            </section>
        <?php elseif ($req['status'] === 'pending'): ?>
            <section class="panel"><p class="field-help">Waiting for the card owner's decision. Nothing has changed.</p></section>
        <?php endif; ?>
    </div>
</div>

<?php require APP_ROOT . '/includes/footer.php'; ?>
