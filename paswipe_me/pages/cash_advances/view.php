<?php
/**
 * Cash Advances - view, mark completed or cancel (instead of delete).
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/cash_advance_functions.php';
require_once APP_ROOT . '/pages/transactions/transaction_functions.php';   // the penalties panel

$currentUser = require_admin();

$advance = find_or_redirect(cash_advance_find(request_id()), 'pages/cash_advances/index.php', 'Cash advance');

if (is_post()) {
    require_csrf('pages/cash_advances/view.php?id=' . $advance['id']);
    $status = input_string($_POST, 'status');

    $openShares = array_filter(cash_advance_shares($advance), static fn ($s) => $s['obligation_id'] && !in_array($s['obligation_status'], ['paid', 'cancelled', 'written_off'], true));
    if ($status === 'cancelled' && $openShares) {
        flash('error', 'This cash advance was given to borrowers who still owe it. Cancel or settle their obligations first.');
    } elseif ($advance['status'] === 'active' && in_array($status, ['completed', 'cancelled'], true)) {
        try {
            // Cancelling also cancels the borrower's cash advance (never one with repayments).
            $note = card_usage_apply([$advance['credit_card_id']], function () use ($advance, $status, $currentUser) {
                db_update('cash_advances', ['status' => $status], $advance['id']);

                return $status === 'cancelled' ? cash_advance_sync_borrower(['status' => 'cancelled'] + $advance, null, $currentUser) : '';
            });
            log_activity('status_change', 'cash_advance', $advance['id'], 'Cash advance marked ' . $status, ['status' => $advance['status']], ['status' => $status]);
            flash('success', trim('Cash advance marked as ' . humanize($status) . '. ' . $note));
        } catch (DomainException $e) {
            flash('error', $e->getMessage());
        }
    } else {
        flash('error', 'That status change is not allowed.');
    }
    redirect('pages/cash_advances/view.php', ['id' => $advance['id']]);
}

$direct = cash_advance_direct_share($advance);
$directLedger = null;
if ($direct) {
    $purchase = borrowed_owner_purchases(null, $direct['id'])[0] ?? null;
    $directLedger = $purchase['ledger'] ?? null;
}
$billedOn = statement_for_date($advance['credit_card_id'], $advance['advance_date']);
$shares = array_values(array_filter(cash_advance_shares($advance), static fn ($s) => $s['obligation_id']));

$pageTitle = 'Cash Advance';
$activeNav = 'cash_advances';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb(['Cash Advances' => url('pages/cash_advances/index.php'), format_date($advance['advance_date']) => null]) ?>

<div class="print-header">
    <p class="print-header__system"><?= e(APP_NAME) ?></p>
    <h2 class="print-header__title">Cash Advance</h2>
    <p class="print-header__meta">Printed: <?= e(date('F j, Y g:i A')) ?></p>
</div>

<section class="page-header">
    <div>
        <h2 class="page-header__title"><?= e(format_money($advance['total_amount'])) ?></h2>
        <p class="page-header__subtitle"><?= e($advance['card_nickname']) ?> •••• <?= e($advance['last_four']) ?> · <?= e(format_date($advance['advance_date'])) ?> · <?= badge($advance['status']) ?></p>
    </div>
    <div class="page-header__actions no-print">
        <a href="<?= e(url('pages/cash_advances/index.php')) ?>" class="btn btn--outline"><?= icon('arrow-left') ?> <span>Back</span></a>
        <button type="button" class="btn btn--outline" data-print><?= icon('printer') ?> <span>Print</span></button>
        <?php if ($advance['status'] === 'active'): ?>
            <?= post_button(url('pages/cash_advances/view.php', ['id' => $advance['id']]), ['status' => 'completed'], 'Mark completed', 'check', 'btn btn--outline') ?>
            <?= post_button(url('pages/cash_advances/view.php', ['id' => $advance['id']]), ['status' => 'cancelled'], 'Cancel', 'x', 'btn btn--outline',
                'Cancel this cash advance? It stays on record as cancelled and can no longer be edited.', false, 'Cancel cash advance?') ?>
        <?php endif; ?>
        <?php if ($advance['status'] !== 'cancelled'): ?>
            <a data-modal-form href="<?= e(url('pages/cash_advances/edit.php', ['id' => $advance['id']])) ?>" class="btn btn--primary"><?= icon('edit') ?> <span>Edit</span></a>
        <?php endif; ?>
    </div>
</section>

<section class="panel">
    <header class="panel__header"><h3>Cash advance details</h3></header>
    <?= render_details([
        'Card'             => related_link($advance['credit_card_id'], $advance['card_nickname'] . ' •••• ' . $advance['last_four'], 'pages/credit_cards/view.php'),
        'Advance date'     => e(format_date($advance['advance_date'])),
        'Reference number' => show($advance['reference_number']),
        'Channel'          => show($advance['channel']),
        'Amount withdrawn' => e(format_money($advance['amount'])),
        'Fee'              => e(format_money($advance['fee'])),
        'Interest'         => e(format_money($advance['interest'])),
        'Total amount'     => '<strong>' . e(format_money($advance['total_amount'])) . '</strong>',
        'Borrower'         => $direct ? related_link($direct['borrower_id'], borrower_name($direct), 'pages/borrowers/view.php') : '<span class="text-muted">For me (no borrower)</span>',
        'Statement'        => e(ucfirst($billedOn['label']))
                              . ($advance['statement_id'] ? ' · ' . related_link($advance['statement_id'], 'recorded SOA of ' . format_date($advance['statement_date']), 'pages/statements/view.php') : ''),
        'Status'           => badge($advance['status']),
        'Notes'            => show_multiline($advance['notes']),
        'Recorded'         => e(format_datetime($advance['created_at'])),
        'Last updated'     => e(format_datetime($advance['updated_at'])),
    ]) ?>
    <p class="field-help no-print">Cash advances are never deleted; cancel a mistaken entry instead.</p>
</section>

<?php if ($direct): ?>
<section class="panel subpanel">
    <header class="panel__header">
        <h3><?= icon('users') ?> <?= e(borrower_name($direct)) ?> repays this cash advance</h3>
        <?php if ($direct['plan_id']): ?>
            <div class="action-bar no-print">
                <a class="btn btn--sm btn--outline" href="<?= e(url('pages/installment_plans/view.php', ['id' => $direct['plan_id']])) ?>"><?= icon('repeat') ?> <span>Installment plan</span></a>
            </div>
        <?php endif; ?>
    </header>
    <?= render_details([
        'Owes'          => '<strong>' . e(format_money($direct['plan_id'] ? $direct['total_repayment'] : $direct['amount'])) . '</strong>'
                           . ($direct['plan_id'] ? ' <span class="text-muted">(' . e($direct['number_of_installments']) . ' monthly installments of ' . e(format_money($direct['installment_amount'])) . ', set up earlier)</span>'
                             : ' <span class="text-muted">(the total amount, in one payment)</span>'),
        'Repaid'        => $directLedger ? e(format_money($directLedger['repaid'])) : '—',
        'Late charges'  => $directLedger ? e(format_money($directLedger['penalty_unpaid'])) . ' unpaid of ' . e(format_money($directLedger['penalties'])) : '—',
        'Outstanding'   => $directLedger ? '<strong>' . e(format_money($directLedger['outstanding'])) . '</strong>' : '—',
        'Repay by'      => $directLedger && $directLedger['next_due'] ? e(format_date($directLedger['next_due']['date'])) : '—',
        'Status'        => $directLedger ? badge($directLedger['status']) : '—',
    ]) ?>
    <h4 class="form-section">Repayment history</h4>
    <?= borrowed_render_repayments(borrowed_repayments($direct['id'])) ?>
    <p class="field-help">A cash advance is a record only: the borrower repays the total in one payment by the card's due date; it is never paid in installments.
        Repaid counts only the repayments confirmed here (recorded by you, confirmed from the borrower's portal payment, or received by staff); marking the cash advance Completed adds no payment.
        Wrong amount or wrong borrower? Use Edit.</p>
</section>
<?php
// Late charges by the card bank's penalty rules (same panel as a card purchase).
$txn = $direct;
$canManage = true;
$penaltyBack = 'pages/cash_advances/view.php?id=' . $advance['id'];
$isLocked = in_array($direct['status'], TXN_LOCKED_STATUSES, true) || $advance['status'] === 'cancelled';
require APP_ROOT . '/pages/transactions/penalties_panel.php';
?>
<?php elseif ($shares): ?>
<section class="panel subpanel">
    <header class="panel__header"><h3><?= icon('users') ?> Given to borrowers (earlier)</h3></header>
    <div class="table-wrap">
        <table class="table table--cards table--compact">
            <thead><tr><th scope="col">Borrower</th><th scope="col">Date given</th><th scope="col" class="num">Given</th><th scope="col">Payments</th>
                <th scope="col">Obligation</th><th scope="col" class="num">Total owed</th><th scope="col" class="num">Remaining</th></tr></thead>
            <tbody>
            <?php foreach ($shares as $s): ?>
                <tr>
                    <td data-label="Borrower"><?= related_link($s['borrower_id'], borrower_name($s), 'pages/borrowers/view.php') ?></td>
                    <td data-label="Date given"><?= e(format_date($s['transaction_date'])) ?></td>
                    <td data-label="Given" class="num"><?= e(format_money($s['amount'])) ?></td>
                    <td data-label="Payments"><?= $s['number_of_installments'] ? e($s['number_of_installments']) . ' × ' . e(format_money($s['installment_amount'])) : '<span class="text-muted">—</span>' ?></td>
                    <td data-label="Obligation"><?= $s['obligation_id'] ? related_link($s['obligation_id'], $s['obligation_number'], 'pages/obligations/view.php') . ' ' . badge($s['obligation_status']) : '<span class="text-muted">—</span>' ?></td>
                    <td data-label="Total owed" class="num"><?= show_money($s['total_amount_due']) ?></td>
                    <td data-label="Remaining" class="num"><?= show_money($s['outstanding_amount']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="field-help">Shares given to borrowers before cash advances became records only. They are paid on their obligations.</p>
</section>
<?php else: ?>
<section class="panel subpanel">
    <header class="panel__header"><h3><?= icon('users') ?> Borrower</h3></header>
    <p class="empty-inline">For you — no borrower. If someone got the cash, choose them with Edit → Borrower: they then repay it, and late charges follow your penalty rules.</p>
</section>
<?php endif; ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
