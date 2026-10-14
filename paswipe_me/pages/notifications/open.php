<?php
/**
 * One of my notifications, opened from the inbox (shown in a modal; also
 * works as a page). Opening marks it read. Shows the record it is about and,
 * when the signed-in user may decide it, the decision itself:
 *   borrower_payment      - approve, or reject / ask for a correction (pages/borrower_payments/review.php)
 *   transaction           - confirm or reject the card repayments the borrower reported (pages/transactions/repayment.php)
 *   authorization_request - approve and apply, or reject (pages/authorizations/decide.php)
 * The forms post to those handlers, which check the role, ownership and the
 * record's current status again; nothing is decided here.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once APP_ROOT . '/includes/notifications.php';
require_once APP_ROOT . '/includes/authorizations.php';
require_once APP_ROOT . '/pages/borrower_payments/payment_functions.php';
require_once APP_ROOT . '/pages/transactions/transaction_functions.php';

$currentUser = require_staff_area();
$isAdmin = user_is_admin($currentUser);

$note = find_or_redirect(notification_mine(request_id(), $currentUser['id']), 'pages/notifications/inbox.php', 'Notification');
notification_mark_read($note);

[$kindLabel] = notification_kind($note);
$recordUrl = notification_record_url($note, $currentUser);
$type = (string) $note['entity_type'];
$entityId = (string) $note['entity_id'];

// The record, found only through the owner-scoped finders (someone else's record is "not found").
$payment = $txn = $req = null;
$repayments = [];
if ($isAdmin && $type === 'borrower_payment') {
    $payment = payment_find($entityId);
} elseif ($isAdmin && $type === 'transaction') {
    $txn = transaction_find($entityId);
    if ($txn) {
        $repayments = db_all(
            "SELECT p.*, pm.display_name AS method_name FROM borrower_payments p LEFT JOIN payment_methods pm ON pm.id = p.payment_method_id
              WHERE p.transaction_id = :t AND p.status IN ('" . implode("', '", BORROWED_REPORTED_STATUSES) . "') ORDER BY p.submitted_at",
            [':t' => $txn['id']]
        );
        // The repayment this notification names comes first; any others waiting on the purchase follow.
        usort($repayments, static fn ($a, $b) => (int) !str_contains($note['title'], $a['payment_number']) <=> (int) !str_contains($note['title'], $b['payment_number']));
    }
} elseif ($type === 'authorization_request') {
    $req = auth_find($entityId);
    if ($req && !$isAdmin && $req['requested_by'] !== $currentUser['id']) {
        $req = null;   // staff see only their own requests
    }
}

$decisions = $payment ? (PAYMENT_TRANSITIONS[$payment['status']] ?? []) : [];
$selections = $payment ? payment_selections($payment['id']) : [];
$attachments = $payment ? db_all(
    'SELECT d.id AS document_id, d.original_filename FROM payment_attachments pa JOIN documents d ON d.id = pa.document_id
      WHERE pa.borrower_payment_id = :id ORDER BY pa.created_at',
    [':id' => $payment['id']]
) : [];
$canDecideReq = $req && $isAdmin && $req['status'] === 'pending' && $req['requested_by'] !== $currentUser['id'];
$original = $req ? (json_decode((string) $req['original_values'], true) ?: []) : [];
$proposed = $req ? (json_decode((string) $req['proposed_values'], true) ?: []) : [];

$pageTitle = $kindLabel;
$activeNav = 'inbox';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb(['My Notifications' => url('pages/notifications/inbox.php'), $note['title'] => null]) ?>

<section class="page-header">
    <div>
        <h2 class="page-header__title"><?= e($note['title']) ?></h2>
        <p class="page-header__subtitle"><?= notification_kind_chip($note) ?> · <?= e(format_datetime($note['created_at'])) ?></p>
    </div>
    <div class="page-header__actions no-print">
        <a href="<?= e(url('pages/notifications/inbox.php')) ?>" class="btn btn--outline"><?= icon('arrow-left') ?> <span>Back</span></a>
        <?php if ($recordUrl): ?>
            <a href="<?= e($recordUrl) ?>" class="btn btn--outline"><?= icon('eye') ?> <span>Open full record</span></a>
        <?php endif; ?>
    </div>
</section>

<section class="panel">
    <div class="document-body"><?= e($note['message']) ?></div>
</section>

<?php if ($payment): ?>
    <section class="panel">
        <header class="panel__header"><h3><?= icon('receipt') ?> Payment <?= e($payment['payment_number']) ?></h3><?= badge($payment['status']) ?></header>
        <?= render_details([
            'Borrower'         => e(borrower_name($payment) . ' (' . $payment['borrower_number'] . ')'),
            'Amount'           => '<strong>' . e(format_money($payment['amount'])) . '</strong>',
            'Payment date'     => e(format_date($payment['payment_date'])),
            'Payment method'   => show($payment['method_name']),
            'Reference number' => show($payment['reference_number']),
            'Notes'            => show_multiline($payment['notes']),
            'Proof of payment' => $attachments ? implode('<br>', array_map(static fn ($a) => '<a target="_blank" rel="noopener" href="' . e(url('pages/documents/view.php', ['id' => $a['document_id']])) . '">' . e($a['original_filename']) . '</a>', $attachments)) : '<span class="text-muted">None attached</span>',
            'Submitted'        => e(format_datetime($payment['submitted_at'])),
        ]) ?>
        <?php if ($selections): ?>
            <h4 class="form-section">What the borrower wants to pay</h4>
            <div class="table-wrap">
                <table class="table table--cards table--compact">
                    <thead><tr><th scope="col">Item</th><th scope="col">Installment</th><th scope="col" class="num">Amount</th></tr></thead>
                    <tbody>
                    <?php foreach ($selections as $sel): ?>
                        <tr><td data-label="Item"><?= e($sel['obligation_number']) ?><?= $sel['description'] ? '<br><span class="text-muted">' . e(excerpt($sel['description'], 60)) . '</span>' : '' ?></td>
                            <td data-label="Installment"><?= $sel['installment_number'] ? '#' . e($sel['installment_number']) . ' · due ' . e(format_date($sel['installment_due'])) : '<span class="text-muted">Whole item / late charges</span>' ?></td>
                            <td data-label="Amount" class="num"><?= e(format_money($sel['amount'])) ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php elseif (empty($payment['transaction_id'])): ?>
            <p class="field-help">The borrower did not choose any item: after approving, post it with Allocate on the payment.</p>
        <?php endif; ?>
    </section>

    <?php if (in_array('approved', $decisions, true)): ?>
        <?= decision_bar([url('pages/borrower_payments/review.php'), ['id' => $payment['id'], 'decision' => 'approved'],
            'Approve payment ' . $payment['payment_number'] . ' of ' . format_money($payment['amount']) . '? It is applied as the borrower chose and the borrower is told.', 'Approve payment?'],
            'payment', ['id' => $payment['id']]) ?>
    <?php else: ?>
        <section class="panel"><p class="field-help">Already decided: this payment is <?= badge($payment['status']) ?>. Nothing is waiting for you.</p></section>
    <?php endif; ?>

<?php elseif ($txn): ?>
    <section class="panel">
        <header class="panel__header"><h3><?= icon('credit-card') ?> Card purchase <?= e($txn['transaction_number']) ?></h3></header>
        <?= render_details([
            'Borrower' => e(borrower_name($txn)),
            'Card'     => e($txn['card_nickname'] . ' •••• ' . $txn['last_four']),
            'Merchant' => show($txn['merchant_name']),
            'Amount'   => e(format_money($txn['amount'])),
            'Date'     => e(format_date($txn['transaction_date'])),
        ]) ?>
    </section>
    <?php if (!$repayments): ?>
        <section class="panel"><p class="field-help">No reported repayment is waiting for you on this purchase.</p></section>
    <?php endif; ?>
    <?php foreach ($repayments as $p): ?>
        <section class="panel no-print notice-decision">
            <header class="panel__header"><h3><?= icon('receipt') ?> Repayment <?= e($p['payment_number']) ?> · <?= e(format_money($p['amount'])) ?></h3><?= badge($p['status']) ?></header>
            <?= render_details([
                'Paid on'   => e(format_date($p['payment_date'])),
                'For'       => show(borrowed_parts_label($p['id'])),
                'Method'    => show($p['method_name']),
                'Reference' => show($p['reference_number']),
                'Notes'     => show_multiline($p['notes']),
            ]) ?>
            <?= decision_bar([url('pages/transactions/repayment.php'), ['id' => $txn['id'], 'action' => 'confirm', 'payment_id' => $p['id']],
                'Approve the repayment of ' . format_money($p['amount']) . '? It is then taken off what the borrower owes.', 'Approve repayment?'],
                'repayment', ['id' => $txn['id'], 'payment_id' => $p['id']]) ?>
        </section>
    <?php endforeach; ?>

<?php elseif ($req): ?>
    <section class="panel">
        <header class="panel__header"><h3><?= icon('shield') ?> <?= e($req['request_number']) ?>: <?= e(AUTH_ACTIONS[$req['action']] ?? $req['action']) ?> <?= e($req['record_ref']) ?></h3><?= auth_status_badge($req['status']) ?></header>
        <?= render_details([
            'Record'        => e((AUTH_RECORD_TYPES[$req['record_type']] ?? $req['record_type']) . ' ' . $req['record_ref']),
            'Requested by'  => e(user_name_of($req['requested_by'])) . ' · ' . e(format_datetime($req['created_at'])),
            'Reason'        => show_multiline($req['reason']),
            'Decided by'    => $req['decided_by'] ? e(user_name_of($req['decided_by'])) . ' · ' . e(format_datetime($req['decided_at'])) : '—',
            'Decision note' => show($req['decision_note']),
            'Result'        => show($req['result']),
        ]) ?>
        <?php if ($original): ?>
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
        <?php endif; ?>
    </section>
    <?php if ($canDecideReq): ?>
        <section class="panel no-print notice-decision">
            <header class="panel__header"><h3><?= icon('shield') ?> Your decision</h3></header>
            <p class="field-help">Approving applies the change at once to the record and every balance that depends on it. A delete voids the record; it is never erased.</p>
            <?= decision_bar([url('pages/authorizations/decide.php'), ['id' => $req['id'], 'decision' => 'approve'],
                'Approve and apply this change now? Balances that depend on it are updated at once.', 'Approve request?'],
                'authorization', ['id' => $req['id']]) ?>
        </section>
    <?php elseif ($req['status'] === 'pending'): ?>
        <section class="panel"><p class="field-help">Waiting for the card owner's decision. Nothing has changed.</p></section>
    <?php endif; ?>

<?php elseif ($note['entity_type'] && !$recordUrl): ?>
    <section class="panel"><p class="field-help">The related record is not available to you.</p></section>
<?php elseif ($note['entity_type'] && in_array($type, ['borrower_payment', 'transaction'], true)): ?>
    <section class="panel"><p class="field-help">The related record was not found. It may have been removed.</p></section>
<?php endif; ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
