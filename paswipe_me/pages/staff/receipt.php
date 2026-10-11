<?php
/**
 * Staff - acknowledgement receipt of a payment received in person.
 * Normal view: details, status, and "Print receipt". ?print=1: a clean page
 * for the printer (no menus); each print is counted, and every print after
 * the first is marked "REPRINT". Printing never records a payment again.
 * A receipt whose payments were all voided shows "VOID".
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once APP_ROOT . '/includes/receipts.php';

$currentUser = require_staff_area();
$receipt = find_or_redirect(receipt_find(request_id()), 'pages/staff/receipts.php', 'Receipt');
$lines = json_decode($receipt['line_items'], true) ?: [];
$payments = receipt_payments($receipt['id']);
$isVoid = $receipt['status'] === 'void';

if (input_string($_GET, 'print') === '1') {
    db_exec('UPDATE payment_receipts SET print_count = print_count + 1, last_printed_at = NOW() WHERE id = :id', [':id' => $receipt['id']]);
    $copy = (int) $receipt['print_count'] + 1;
    log_activity($copy > 1 ? 'reprint_receipt' : 'print_receipt', 'payment_receipt', $receipt['id'], ($copy > 1 ? 'Reprinted' : 'Printed') . ' receipt ' . $receipt['receipt_number'] . ' (copy ' . $copy . ')');
    send_no_cache_headers();
    ?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Receipt <?= e($receipt['receipt_number']) ?></title>
    <link rel="stylesheet" href="<?= e(asset('assets/css/receipt.css')) ?>">
</head>
<body class="receipt-page">
<main class="receipt<?= $isVoid ? ' receipt--void' : '' ?>">
    <?php if ($isVoid): ?><p class="receipt__stamp">VOID</p><?php elseif ($receipt['amended_note']): ?><p class="receipt__stamp">AMENDED<?= $copy > 1 ? ' · copy ' . e($copy) : '' ?></p><?php elseif ($copy > 1): ?><p class="receipt__stamp receipt__stamp--copy">REPRINT · copy <?= e($copy) ?></p><?php endif; ?>
    <header class="receipt__head">
        <div>
            <p class="receipt__system"><?= e(APP_NAME) ?></p>
            <h1>Acknowledgement Receipt</h1>
        </div>
        <dl class="receipt__meta">
            <div><dt>Receipt no.</dt><dd><?= e($receipt['receipt_number']) ?></dd></div>
            <div><dt>Date and time</dt><dd><?= e(format_datetime($receipt['received_at'])) ?></dd></div>
            <div><dt>Payment date</dt><dd><?= e(format_date($receipt['payment_date'])) ?></dd></div>
        </dl>
    </header>

    <section class="receipt__parties">
        <p><span>Received from</span><strong><?= e(trim($receipt['first_name'] . ' ' . $receipt['last_name'])) ?></strong> (<?= e($receipt['borrower_number']) ?>)</p>
        <p><span>Received by</span><strong><?= e(user_name_of($receipt['received_by'])) ?></strong> for <?= e(user_name_of($receipt['owner_user_id'])) ?></p>
    </section>

    <table class="receipt__lines">
        <thead><tr><th>Item</th><th>Reference</th><th class="num">Original amount</th><th class="num">Balance before</th><th class="num">Paid</th><th class="num">Balance after</th></tr></thead>
        <tbody>
        <?php foreach ($lines as $l): ?>
            <tr><td><?= e($l['type']) ?><br><small><?= e($l['title']) ?></small></td><td><?= e($l['ref']) ?></td>
                <td class="num"><?= $l['original'] !== null ? e(format_money($l['original'])) : '—' ?></td>
                <td class="num"><?= $l['before'] !== null ? e(format_money($l['before'])) : '—' ?></td>
                <td class="num"><strong><?= e(format_money($l['paid'])) ?></strong></td>
                <td class="num"><?= e(format_money($l['after'])) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <dl class="receipt__totals">
        <div><dt>Previous outstanding balance</dt><dd><?= e(format_money($receipt['previous_balance'])) ?></dd></div>
        <div class="is-total"><dt>Amount tendered</dt><dd><?= e(format_money($receipt['amount_tendered'])) ?></dd></div>
        <div><dt>Remaining balance after payment</dt><dd><?= e(format_money($receipt['remaining_balance'])) ?></dd></div>
        <div><dt>Payment method</dt><dd><?= e($receipt['method_label'] ?: 'Cash') ?><?= $receipt['reference_number'] ? ' · Ref. ' . e($receipt['reference_number']) : '' ?></dd></div>
        <?php if ($receipt['remarks']): ?><div><dt>Remarks</dt><dd><?= e($receipt['remarks']) ?></dd></div><?php endif; ?>
        <div><dt>Payment records</dt><dd><?= e(implode(', ', array_column($payments, 'payment_number'))) ?></dd></div>
    </dl>
    <?php if ($receipt['amended_note']): ?><p class="receipt__void-note">Amended after it was issued:<br><?= nl2br(e($receipt['amended_note'])) ?></p><?php endif; ?>
    <?php if ($isVoid): ?><p class="receipt__void-note">This receipt is void: <?= e($receipt['void_reason']) ?> (<?= e(format_datetime($receipt['voided_at'])) ?>)</p><?php endif; ?>

    <footer class="receipt__signatures">
        <div><span></span><p>Received by (staff)</p></div>
        <div><span></span><p>Paid by (borrower)</p></div>
    </footer>
    <p class="receipt__foot">This acknowledges the payment above. Balances are as of the time of payment.</p>
    <p class="receipt__actions"><button type="button" data-receipt-print>Print</button></p>
</main>
<script src="<?= e(asset('assets/js/receipt.js')) ?>"></script>
</body>
</html>
<?php
    exit;
}

$pageTitle = 'Receipt ' . $receipt['receipt_number'];
$activeNav = 'staff_receipts';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb(['Receipts' => url('pages/staff/receipts.php'), $receipt['receipt_number'] => null]) ?>

<section class="page-header">
    <div>
        <h2 class="page-header__title">Receipt <?= e($receipt['receipt_number']) ?></h2>
        <p class="page-header__subtitle"><?= e(format_money($receipt['amount_tendered'])) ?> from <?= e(trim($receipt['first_name'] . ' ' . $receipt['last_name'])) ?> · <?= e(format_datetime($receipt['received_at'])) ?>
            · <?= $isVoid ? status_badge('void', ['void' => 'danger']) : status_badge('issued', ['issued' => 'success']) ?></p>
    </div>
    <div class="page-header__actions no-print">
        <a href="<?= e(url('pages/staff/receipt.php', ['id' => $receipt['id'], 'print' => 1])) ?>" target="_blank" rel="noopener" class="btn btn--primary"><?= icon('printer') ?> <span><?= (int) $receipt['print_count'] > 0 ? 'Reprint receipt' : 'Print receipt' ?></span></a>
    </div>
</section>

<?php if ($receipt['amended_note']): ?>
    <div class="alert alert--warning"><?= icon('alert') ?><span><strong>Amended after it was issued:</strong><br><?= nl2br(e($receipt['amended_note'])) ?></span></div>
<?php endif; ?>
<?php if ($isVoid): ?>
    <div class="alert alert--error"><?= icon('alert') ?><span>This receipt is void: <?= e($receipt['void_reason']) ?>. Its payments no longer count.</span></div>
<?php endif; ?>

<div class="detail-layout">
    <section class="panel">
        <header class="panel__header"><h3>Payment</h3></header>
        <?= render_details([
            'Receipt no.'       => e($receipt['receipt_number']),
            'Borrower'          => e(trim($receipt['first_name'] . ' ' . $receipt['last_name']) . ' (' . $receipt['borrower_number'] . ')'),
            'Received by'       => e(user_name_of($receipt['received_by'])),
            'Received at'       => e(format_datetime($receipt['received_at'])),
            'Payment date'      => e(format_date($receipt['payment_date'])),
            'Previous balance'  => e(format_money($receipt['previous_balance'])),
            'Amount tendered'   => '<strong>' . e(format_money($receipt['amount_tendered'])) . '</strong>',
            'Remaining balance' => e(format_money($receipt['remaining_balance'])),
            'Method'            => e($receipt['method_label'] ?: 'Cash') . ($receipt['reference_number'] ? ' · ' . e($receipt['reference_number']) : ''),
            'Remarks'           => show($receipt['remarks']),
            'Printed'           => e($receipt['print_count']) . ' time(s)' . ($receipt['last_printed_at'] ? ', last ' . e(format_datetime($receipt['last_printed_at'])) : ''),
        ]) ?>
    </section>
    <div class="detail-side">
        <section class="panel">
            <header class="panel__header"><h3>Applied to</h3></header>
            <ul class="child-list">
                <?php foreach ($lines as $l): ?>
                    <li><span><?= e($l['type']) ?> <?= e($l['ref']) ?> · <?= e($l['title']) ?><br><span class="text-muted"><?= e(format_money($l['before'])) ?> → <?= e(format_money($l['after'])) ?></span></span>
                        <strong><?= e(format_money($l['paid'])) ?></strong></li>
                <?php endforeach; ?>
            </ul>
        </section>
        <section class="panel">
            <header class="panel__header"><h3>Payment records</h3></header>
            <ul class="child-list">
                <?php foreach ($payments as $p): ?>
                    <li><span><?= e($p['payment_number']) ?> <?= badge($p['status']) ?></span>
                        <span><strong><?= e(format_money($p['amount'])) ?></strong>
                        <?php if ($p['status'] !== 'cancelled'): ?>
                            <?php if (user_is_admin($currentUser)): /* the owner changes it directly; staff ask for authorization */ ?>
                                <a data-modal-form class="btn btn--sm btn--outline" href="<?= e(url('pages/borrower_payments/override.php', ['id' => $p['id']])) ?>"><?= icon('lock') ?> <span>Override</span></a>
                                <a data-modal-form class="btn btn--sm btn--outline btn--danger-outline" href="<?= e(url('pages/borrower_payments/void.php', ['id' => $p['id']])) ?>"><?= icon('trash') ?> <span>Delete</span></a>
                            <?php else: ?>
                                <a data-modal-form class="btn btn--sm btn--outline" href="<?= e(url('pages/authorizations/request.php', ['type' => 'borrower_payment', 'id' => $p['id'], 'action' => 'edit'])) ?>"><?= icon('edit') ?> <span>Edit</span></a>
                                <a data-modal-form class="btn btn--sm btn--outline btn--danger-outline" href="<?= e(url('pages/authorizations/request.php', ['type' => 'borrower_payment', 'id' => $p['id'], 'action' => 'delete'])) ?>"><?= icon('trash') ?> <span>Delete</span></a>
                            <?php endif; ?>
                        <?php endif; ?></span></li>
                <?php endforeach; ?>
            </ul>
            <p class="field-help">A confirmed payment can only be corrected or voided with the card owner's authorization.</p>
        </section>
    </div>
</div>

<?php require APP_ROOT . '/includes/footer.php'; ?>
