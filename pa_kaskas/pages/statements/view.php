<?php
/**
 * Statements - one card's billing period: the records already in the database
 * for it, the bank's SOA when recorded (as entered), its payments and status,
 * and the difference between the SOA and the records. Opens in the modal.
 *   view.php?id=…                  a recorded SOA
 *   view.php?card=…&start=…&end=…  a period without a recorded SOA
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/statement_functions.php';

$currentUser = require_admin();

$statement = null;
if (input_string($_GET, 'id') !== '') {
    $statement = find_or_redirect(statement_find(request_id()), 'pages/statements/index.php', 'Statement');
    $card = db_row('SELECT * FROM credit_cards WHERE id = :c', [':c' => $statement['credit_card_id']]);
    $period = statement_period_of($statement, $card);
} else {
    $cardId = input_string($_GET, 'card');
    $card = is_valid_uuid($cardId) ? db_row('SELECT * FROM credit_cards c WHERE c.id = :c AND ' . owner_scope('credit_cards', 'c'), [':c' => $cardId]) : null;
    $dateOk = static fn ($d) => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
    if (!$card || !$dateOk(input_string($_GET, 'start')) || !$dateOk(input_string($_GET, 'end'))) {
        flash('error', 'Statement period not found.');
        redirect('pages/statements/index.php');
    }
    $period = card_period_containing(card_period_context($card), input_string($_GET, 'end'));
    if ($period['statement_id']) {
        redirect('pages/statements/view.php', ['id' => $period['statement_id']]);
    }
}

// A prepared statement (no bank SOA yet) shows like any period without an SOA.
$prepared = null;
if ($statement && $statement['soa_recorded_at'] === null) {
    [$prepared, $statement] = [$statement, null];
}
if (!$statement && !$prepared) {
    $prepared = db_row('SELECT * FROM credit_card_statements WHERE credit_card_id = :c AND cutoff_date = :e AND soa_recorded_at IS NULL',
        [':c' => $card['id'], ':e' => $period['end']]);
}
// Keep the card's upcoming statements current (writes only when its records changed).
if ($period['end'] >= date('Y-m-d') && $card['status'] === 'active') {
    statement_prepare_card($card['id']);
    $prepared = $prepared ? db_row('SELECT * FROM credit_card_statements WHERE id = :id', [':id' => $prepared['id']]) : $prepared;
}

$lines = statement_period_lines($card['id'], $period['start'], $period['end']);
$summary = statement_lines_summary($lines);
$missing = statement_missing_links($card['id'], $period['end']);
$payments = $statement ? db_all(
    'SELECT p.id, p.payment_date, p.amount, p.reference_number, p.payment_type, pm.display_name AS method_name
       FROM credit_card_payments p LEFT JOIN payment_methods pm ON pm.id = p.payment_method_id
      WHERE p.statement_id = :s ORDER BY p.payment_date, p.created_at',
    [':s' => $statement['id']]
) : [];
$calc = $statement ? statement_expected($statement, $summary) : null;
$expected = $calc['expected'] ?? null;
$difference = $statement ? round((float) $statement['total_amount_due'] - $expected, 2) : null;
$autoPayments = $statement ? (float) db_value(
    "SELECT COALESCE(SUM(amount), 0) FROM credit_card_payments WHERE credit_card_id = :c AND payment_date BETWEEN :s AND :e AND payment_type LIKE 'Borrower payment%'",
    [':c' => $card['id'], ':s' => $period['start'], ':e' => $period['end']]
) : 0.0;
$history = $statement ? db_all('SELECT * FROM statement_reconciliations WHERE statement_id = :s ORDER BY created_at DESC', [':s' => $statement['id']]) : [];
$usage = $statement ? statement_usage($statement['id']) : ['total' => 0];
$month = statement_month_label($period['end']);

$pageTitle = 'Statement';
$activeNav = 'statements';
require APP_ROOT . '/includes/header.php';

$missingButtons = '<div class="missing-actions no-print">';
foreach ($missing as $label => [$href, $iconName]) {
    $missingButtons .= '<a data-modal-form href="' . e($href) . '" class="btn btn--sm btn--outline">' . icon($iconName) . ' <span>' . e($label) . '</span></a>';
}
$missingButtons .= '</div>';
?>

<?= render_breadcrumb(['Statements' => url('pages/statements/index.php', ['card' => $card['id']]), $card['card_nickname'] . ' — ' . $month => null]) ?>

<div class="print-header">
    <p class="print-header__system"><?= e(APP_NAME) ?></p>
    <h2 class="print-header__title">Statement — <?= e($card['card_nickname']) ?> — <?= e($month) ?></h2>
    <p class="print-header__meta">Printed: <?= e(date('F j, Y g:i A')) ?></p>
</div>

<section class="page-header">
    <div>
        <h2 class="page-header__title"><?= e($card['card_nickname']) ?> — <?= e($month) ?> statement</h2>
        <p class="page-header__subtitle"><?= e($card['issuer']) ?> •••• <?= e($card['last_four']) ?> · period <?= e(format_date($period['start'])) ?> – <?= e(format_date($period['end'])) ?>
            · <?= statement_status_badge($statement ? $statement['pay_status'] : ($prepared ? 'prepared' : 'unrecorded'), $statement['due_date'] ?? null) ?></p>
    </div>
    <div class="page-header__actions no-print">
        <a href="<?= e(url('pages/statements/index.php', ['card' => $card['id']])) ?>" class="btn btn--outline"><?= icon('arrow-left') ?> <span>Back</span></a>
        <button type="button" class="btn btn--outline" data-print><?= icon('printer') ?> <span>Print</span></button>
        <?php if ($statement): ?>
            <a data-modal-form href="<?= e(url('pages/statements/edit.php', ['id' => $statement['id']])) ?>" class="btn btn--outline"><?= icon('edit') ?> <span>Edit SOA</span></a>
            <?php if ((float) $statement['remaining'] > 0.004): ?>
                <a data-modal-form href="<?= e(url('pages/statements/pay.php', ['id' => $statement['id']])) ?>" class="btn btn--primary"><?= icon('wallet') ?> <span>Pay</span></a>
            <?php endif; ?>
        <?php else: ?>
            <a data-modal-form href="<?= e(statement_soa_url($card['id'], $period)) ?>" class="btn btn--primary"><?= icon('plus') ?> <span>Record SOA</span></a>
        <?php endif; ?>
    </div>
</section>

<?php if ($statement): ?>
    <section class="figure-grid">
        <div class="figure figure--total"><p class="figure__label">Actual SOA amount</p><p class="figure__value"><?= e(format_money($statement['total_amount_due'])) ?></p></div>
        <div class="figure"><p class="figure__label">Minimum amount due</p><p class="figure__value"><?= e(format_money($statement['minimum_amount_due'])) ?></p></div>
        <div class="figure"><p class="figure__label">Payment due date</p><p class="figure__value"><?= e(format_date($statement['due_date'])) ?></p></div>
        <div class="figure"><p class="figure__label">Paid</p><p class="figure__value"><?= e(format_money($statement['paid'])) ?></p></div>
        <div class="figure"><p class="figure__label">Remaining</p><p class="figure__value"><?= e(format_money($statement['remaining'])) ?></p></div>
        <div class="figure"><p class="figure__label">Status</p><p class="figure__value"><?= statement_status_badge($statement['pay_status'], $statement['due_date']) ?></p></div>
    </section>

    <?php $formula = e(format_money($calc['previous'])) . ' <span class="text-muted">(' . e($calc['basis']) . ')</span> + charges ' . e(format_money($summary['charges']))
        . ' − payments and credits ' . e(format_money($summary['payments'])) . ' = <strong>' . e(format_money($expected)) . '</strong>'; ?>
    <?php if (abs($difference) < 0.005): ?>
        <div class="recon-result recon-result--balanced" role="status">
            <h3><?= icon('check') ?> The SOA amount matches your records</h3>
            <p>Previous balance <?= $formula ?>.</p>
        </div>
    <?php else: ?>
        <div class="recon-result recon-result--difference" role="status">
            <h3><?= icon('alert') ?> The SOA amount differs from your records</h3>
            <dl class="recon-figures">
                <div><dt>Actual SOA amount (as entered)</dt><dd><?= e(format_money($statement['total_amount_due'])) ?></dd></div>
                <div><dt>From your records</dt><dd><?= e(format_money($expected)) ?></dd></div>
                <div><dt>Difference</dt><dd class="text-danger"><?= e(format_money($difference)) ?></dd></div>
            </dl>
            <p class="recon-help">From your records = previous balance <?= $formula ?>. The SOA amount you entered is kept as it is.</p>
            <div class="recon-steps">
                <p><strong>How to balance it</strong> — compare the SOA line by line:</p>
                <ol>
                    <?php if ($statement['soa_previous_balance'] === null): ?>
                        <li><strong>Previous balance:</strong> the SOA's "Previous balance" includes what was not paid on earlier statements (e.g. unpaid installments).
                            Your records carry <?= e(format_money($calc['carried'])) ?> into this period. <a data-modal-form href="<?= e(url('pages/statements/edit.php', ['id' => $statement['id']])) ?>">Enter the SOA's previous balance</a> so only this month is compared.</li>
                    <?php else: ?>
                        <li><strong>Previous balance:</strong> using the SOA's <?= e(format_money($statement['soa_previous_balance'])) ?> (your records carry <?= e(format_money($calc['carried'])) ?>; a difference there is from earlier months).</li>
                    <?php endif; ?>
                    <li><strong>Late payment fee, finance charge, interest</strong> printed on the SOA: add each with <em>Annual fee, interest or charge</em> below, dated as on the SOA.</li>
                    <li><strong>Payments:</strong> the bank only counts payments it received.<?= $autoPayments > 0 ? ' ' . e(format_money($autoPayments)) . ' in this period was recorded automatically from borrower payments — if that money did not reach the bank, correct those card payments (Edit).' : '' ?>
                        A payment the bank shows that is missing here: add it with <em>Payment or credit</em>.</li>
                    <li>Anything left (a bank adjustment): explain it in the SOA notes.</li>
                </ol>
            </div>
            <a href="#review" class="btn btn--sm btn--primary no-print"><?= icon('search') ?> <span>Review transactions and add a missing one</span></a>
        </div>
    <?php endif; ?>
<?php else: ?>
    <div class="recon-result recon-result--pending" role="status">
        <h3>SOA not recorded yet</h3>
        <p><?= $prepared ? 'Prepared automatically ' . e(format_datetime($prepared['prepared_at'])) . ' from the records already in the system' : 'These are the records already in the system for this period' ?>
            — installments and loan repayments are listed by their schedule. When the bank's SOA arrives, click <strong>Record SOA</strong> and type its amount, minimum due and due date.</p>
    </div>
    <section class="figure-grid">
        <div class="figure"><p class="figure__label">Records</p><p class="figure__value"><?= e($summary['count']) ?></p></div>
        <div class="figure"><p class="figure__label">Charges</p><p class="figure__value"><?= e(format_money($summary['charges'])) ?></p></div>
        <div class="figure"><p class="figure__label">Payments and credits</p><p class="figure__value"><?= e(format_money($summary['payments'])) ?></p></div>
    </section>
<?php endif; ?>

<?php if ($lines['warnings']): ?>
    <div class="alert alert--warning" role="status"><?= icon('alert') ?>
        <span><strong>Needs attention:</strong> <?= e(implode(' ', $lines['warnings'])) ?></span></div>
<?php endif; ?>

<section class="panel subpanel" id="review">
    <header class="panel__header">
        <h3><?= icon('layers') ?> Transactions in this period (<?= e($summary['count']) ?>)</h3>
    </header>
    <p class="field-help">From your records, by their own dates (<?= e(format_date($period['start'])) ?> – <?= e(format_date($period['end'])) ?>); each installment or loan repayment appears in the period of its scheduled date.</p>
    <div class="add-missing no-print"><span class="add-missing__label"><?= icon('plus') ?> Add missing transaction:</span><?= $missingButtons ?></div>
    <?php if (!$lines['lines'] && $summary['opening'] == 0): ?>
        <p class="empty-inline">No records dated in this period.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table--cards table--compact">
                <thead><tr><th scope="col">Date</th><th scope="col">Description</th><th scope="col">Category</th><th scope="col">Installment progress</th><th scope="col">Status</th><th scope="col" class="num">Amount</th><th scope="col" class="actions no-print"><span class="visually-hidden">Actions</span></th></tr></thead>
                <tbody>
                <?php if ($summary['opening'] > 0): ?>
                    <tr><td data-label="Date">—</td><td data-label="Description">Opening balance</td><td data-label="Category">Opening balance</td><td></td><td></td>
                        <td data-label="Amount" class="num"><?= e(format_money($summary['opening'])) ?></td><td></td></tr>
                <?php endif; ?>
                <?php foreach ($lines['lines'] as $l): $credit = in_array($l['part'], STATEMENT_CREDIT_PARTS, true); $edit = statement_line_edit_url($l); ?>
                    <tr>
                        <td data-label="Date"><?= e(format_date($l['date'])) ?></td>
                        <td data-label="Description"><?= e($l['description']) ?></td>
                        <td data-label="Category"><?= e(STATEMENT_PARTS[$l['part']]) ?><?= $l['category'] && $l['category'] !== STATEMENT_PARTS[$l['part']] ? '<br><span class="text-muted">' . e($l['category']) . '</span>' : '' ?></td>
                        <td data-label="Installment progress"><?= $l['progress'] ? '<strong class="progress-pill">' . e($l['progress']) . '</strong> ' : '' ?><?= $l['note'] ? '<span class="text-muted">' . e($l['note']) . '</span>' : '' ?></td>
                        <td data-label="Status"><?= $l['status'] ? badge($l['status']) : '' ?></td>
                        <td data-label="Amount" class="num"><?= $credit ? '− ' : '' ?><?= e(format_money($l['amount'])) ?></td>
                        <td class="actions no-print"><div class="row-actions">
                            <a data-modal-form href="<?= e($l['link']) ?>" class="icon-button" title="View" aria-label="View <?= e($l['description']) ?>"><?= icon('eye') ?></a>
                            <?php if ($edit): ?><?= icon_link($edit, 'edit', 'Edit ' . $l['description']) ?><?php endif; ?>
                        </div></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot><tr><td colspan="5">Charges − payments and credits</td><td class="num"><?= e(format_money($summary['charges'] - $summary['payments'])) ?></td><td></td></tr></tfoot>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php if ($statement): ?>
    <section class="panel subpanel">
        <header class="panel__header">
            <h3><?= icon('wallet') ?> Payments for this statement</h3>
            <?php if ((float) $statement['remaining'] > 0.004): ?>
                <a data-modal-form href="<?= e(url('pages/statements/pay.php', ['id' => $statement['id']])) ?>" class="btn btn--sm btn--primary no-print"><?= icon('plus') ?> <span>Pay</span></a>
            <?php endif; ?>
        </header>
        <?php if (!$payments): ?>
            <p class="empty-inline">No payment recorded for this statement yet.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table table--cards table--compact">
                    <thead><tr><th scope="col">Date</th><th scope="col">Type</th><th scope="col">Paid from</th><th scope="col">Reference</th><th scope="col" class="num">Amount</th><th scope="col" class="actions no-print"><span class="visually-hidden">Actions</span></th></tr></thead>
                    <tbody>
                    <?php foreach ($payments as $p): ?>
                        <tr>
                            <td data-label="Date"><?= e(format_date($p['payment_date'])) ?></td>
                            <td data-label="Type"><?= show($p['payment_type']) ?></td>
                            <td data-label="Paid from"><?= show($p['method_name']) ?></td>
                            <td data-label="Reference"><?= show($p['reference_number']) ?></td>
                            <td data-label="Amount" class="num"><?= e(format_money($p['amount'])) ?></td>
                            <td class="actions no-print"><div class="row-actions"><?= icon_link(url('pages/card_payments/edit.php', ['id' => $p['id']]), 'edit', 'Edit payment') ?></div></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot><tr><td colspan="4">Paid · remaining <?= e(format_money($statement['remaining'])) ?></td><td class="num"><?= e(format_money($statement['paid'])) ?></td><td></td></tr></tfoot>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="panel subpanel">
        <header class="panel__header"><h3>SOA details</h3></header>
        <?= render_details([
            'Statement cutoff' => e(format_date($statement['cutoff_date'] ?: $statement['statement_date'])),
            'Statement date'   => e(format_date($statement['statement_date'])),
            'Statement number' => show($statement['statement_number']),
            'SOA file'         => related_link($statement['attachment_document_id'], $statement['original_filename'], 'pages/documents/view.php'),
            'Notes'            => show_multiline($statement['notes']),
            'Recorded'         => e(format_datetime($statement['created_at'])),
            'Last updated'     => e(format_datetime($statement['updated_at'])),
        ]) ?>
        <?php if ($history): ?>
            <p class="field-help">Earlier SOA comparisons (kept as history):
                <?php foreach ($history as $h): ?>
                    <br><?= e(format_datetime($h['created_at'])) ?> — bank <?= e(format_money($h['bank_statement_balance'])) ?>, system then <?= e(format_money($h['system_statement_balance'])) ?> <?= reconciliation_badge($h['status']) ?>
                <?php endforeach; ?></p>
        <?php endif; ?>
        <?php if ($usage['total'] === 0 && !$history): ?>
            <div class="no-print">
                <?= post_button(url('pages/statements/delete.php'), ['id' => $statement['id']], 'Delete this SOA', 'trash', 'btn btn--sm btn--outline',
                    'Delete this recorded SOA? The transactions are not affected.') ?>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
