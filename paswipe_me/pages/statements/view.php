<?php
/**
 * Statements - statement breakdown with payments, charges and advances linked to it.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/statement_functions.php';

$currentUser = require_admin();

$statement = find_or_redirect(statement_find(request_id()), 'pages/statements/index.php', 'Statement');
$id = $statement['id'];

$payments = db_all('SELECT id, payment_date, amount, reference_number FROM credit_card_payments WHERE statement_id = :id ORDER BY payment_date', [':id' => $id]);
$charges  = db_all('SELECT id, charge_date, charge_type, amount FROM bank_charges WHERE statement_id = :id ORDER BY charge_date', [':id' => $id]);
$advances = db_all('SELECT id, advance_date, amount, total_amount FROM cash_advances WHERE statement_id = :id ORDER BY advance_date', [':id' => $id]);
$paid = array_sum(array_column($payments, 'amount'));
$usage = statement_usage($id);

$pageTitle = 'Statement Details';
$activeNav = 'statements';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb(['Statements' => url('pages/statements/index.php'), statement_label($statement) => null]) ?>

<div class="print-header">
    <p class="print-header__system"><?= e(APP_NAME) ?></p>
    <h2 class="print-header__title">Credit Card Statement</h2>
    <p class="print-header__meta">Printed: <?= e(date('F j, Y g:i A')) ?></p>
</div>

<section class="page-header">
    <div>
        <h2 class="page-header__title"><?= e($statement['card_nickname']) ?> •••• <?= e($statement['last_four']) ?></h2>
        <p class="page-header__subtitle">Statement <?= e(format_date($statement['statement_date'])) ?> · due <?= e(format_date($statement['due_date'])) ?></p>
    </div>
    <div class="page-header__actions no-print">
        <a href="<?= e(url('pages/statements/index.php')) ?>" class="btn btn--outline"><?= icon('arrow-left') ?> <span>Back</span></a>
        <button type="button" class="btn btn--outline" data-print><?= icon('printer') ?> <span>Print</span></button>
        <a href="<?= e(url('pages/card_payments/create.php', ['credit_card_id' => $statement['credit_card_id'], 'statement_id' => $id])) ?>" class="btn btn--outline"><?= icon('banknote') ?> <span>Record payment</span></a>
        <a href="<?= e(url('pages/statements/edit.php', ['id' => $id])) ?>" class="btn btn--primary"><?= icon('edit') ?> <span>Edit</span></a>
    </div>
</section>

<section class="figure-grid">
    <div class="figure"><p class="figure__label">Total amount due</p><p class="figure__value"><?= e(format_money($statement['total_amount_due'])) ?></p></div>
    <div class="figure"><p class="figure__label">Minimum due</p><p class="figure__value"><?= e(format_money($statement['minimum_amount_due'])) ?></p></div>
    <div class="figure"><p class="figure__label">Paid against statement</p><p class="figure__value"><?= e(format_money($paid)) ?></p></div>
    <div class="figure"><p class="figure__label">Remaining</p><p class="figure__value"><?= e(format_money(max(0, (float) $statement['total_amount_due'] - $paid))) ?></p></div>
</section>

<div class="detail-layout">
    <section class="panel">
        <header class="panel__header"><h3>Statement breakdown</h3></header>
        <?= render_details([
            'Card'               => related_link($statement['credit_card_id'], $statement['card_nickname'] . ' •••• ' . $statement['last_four'], 'pages/credit_cards/view.php'),
            'Statement number'   => show($statement['statement_number']),
            'Statement date'     => e(format_date($statement['statement_date'])),
            'Cut-off date'       => show($statement['cutoff_date'] ? format_date($statement['cutoff_date']) : null),
            'Due date'           => e(format_date($statement['due_date'])),
            'Previous balance'   => e(format_money($statement['previous_balance'])),
            'Purchases'          => e(format_money($statement['purchases'])),
            'Payments'           => e(format_money($statement['payments'])),
            'Interest'           => e(format_money($statement['interest'])),
            'Finance charges'    => e(format_money($statement['finance_charges'])),
            'Late fees'          => e(format_money($statement['late_fees'])),
            'Other charges'      => e(format_money($statement['other_charges'])),
            'Credits'            => e(format_money($statement['credits'])),
            'Adjustments'        => e(format_money($statement['adjustments'])),
            'Total amount due'   => '<strong>' . e(format_money($statement['total_amount_due'])) . '</strong>',
            'Minimum amount due' => e(format_money($statement['minimum_amount_due'])),
            'Statement file'     => related_link($statement['attachment_document_id'], $statement['original_filename'], 'pages/documents/view.php'),
            'Created'            => e(format_datetime($statement['created_at'])),
            'Last updated'       => e(format_datetime($statement['updated_at'])),
        ]) ?>
    </section>

    <div class="detail-side">
        <section class="panel">
            <header class="panel__header"><h3>Card payments</h3></header>
            <?php if (!$payments): ?><p class="empty-inline">No payments linked.</p><?php else: ?>
                <ul class="child-list"><?php foreach ($payments as $p): ?>
                    <li><a href="<?= e(url('pages/card_payments/view.php', ['id' => $p['id']])) ?>"><?= e(format_date($p['payment_date'])) ?></a><strong><?= e(format_money($p['amount'])) ?></strong></li>
                <?php endforeach; ?></ul>
            <?php endif; ?>
        </section>
        <section class="panel">
            <header class="panel__header"><h3>Bank charges</h3></header>
            <?php if (!$charges): ?><p class="empty-inline">No charges linked.</p><?php else: ?>
                <ul class="child-list"><?php foreach ($charges as $c): ?>
                    <li><a href="<?= e(url('pages/bank_charges/view.php', ['id' => $c['id']])) ?>"><?= e($c['charge_type']) ?> · <?= e(format_date($c['charge_date'])) ?></a><strong><?= e(format_money($c['amount'])) ?></strong></li>
                <?php endforeach; ?></ul>
            <?php endif; ?>
        </section>
        <section class="panel">
            <header class="panel__header"><h3>Cash advances</h3></header>
            <?php if (!$advances): ?><p class="empty-inline">No cash advances linked.</p><?php else: ?>
                <ul class="child-list"><?php foreach ($advances as $a): ?>
                    <li><a href="<?= e(url('pages/cash_advances/view.php', ['id' => $a['id']])) ?>"><?= e(format_date($a['advance_date'])) ?></a><strong><?= e(format_money($a['total_amount'])) ?></strong></li>
                <?php endforeach; ?></ul>
            <?php endif; ?>
        </section>
        <section class="panel panel--danger no-print">
            <header class="panel__header"><h3>Delete</h3></header>
            <?php if ($usage['total'] > 0): ?>
                <p class="field-help">Linked to <?= e(usage_text($usage)) ?>, so it cannot be deleted.</p>
            <?php else: ?>
                <p class="field-help">Delete only a statement entered by mistake.</p>
                <?= post_button(url('pages/statements/delete.php'), ['id' => $id], 'Delete statement', 'trash', 'btn btn--danger btn--block',
                    'Are you sure you want to delete this statement? This cannot be undone.') ?>
            <?php endif; ?>
        </section>
    </div>
</div>

<?php require APP_ROOT . '/includes/footer.php'; ?>
