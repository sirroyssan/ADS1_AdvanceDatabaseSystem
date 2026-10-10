<?php
/**
 * Statements - one statement: its figures (generated from the card's records,
 * or as typed in for a manual statement), the records it contains, and the
 * reconciliation against the bank's Statement of Account (SOA).
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/statement_functions.php';

$currentUser = require_admin();

$found = find_or_redirect(statement_find(request_id()), 'pages/statements/index.php', 'Statement');
// Bring the card's statements up to date with its records before showing figures.
statement_sync_card($found['credit_card_id']);
$statement = statement_find($found['id']);
$id = $statement['id'];
$card = db_row('SELECT * FROM credit_cards WHERE id = :c', [':c' => $statement['credit_card_id']]);
$generated = $statement['source'] === 'generated';

$period = $generated ? statement_period_lines($card['id'], $statement['period_start_date'], $statement['cutoff_date']) : null;
$recon = statement_reconciliation_state($statement);
$history = db_all(
    'SELECT r.*, COALESCE(NULLIF(TRIM(CONCAT_WS(\' \', u.first_name, u.last_name)), \'\'), u.email) AS user_name
       FROM statement_reconciliations r LEFT JOIN users u ON u.id = r.created_by
      WHERE r.statement_id = :s ORDER BY r.created_at DESC, r.id DESC',
    [':s' => $id]
);
$missing = statement_missing_links($statement);

$charges = 0.0;
foreach (STATEMENT_PARTS as $part => $label) {
    if (!in_array($part, STATEMENT_CREDIT_PARTS, true)) {
        $charges += (float) $statement[$part];
    }
}
$paidCredits = (float) $statement['payments'] + (float) $statement['credits'];
$minimumUnconfirmed = $generated && $statement['minimum_due_status'] === 'unconfirmed';
$dueUnconfirmed = $generated && $statement['due_date_status'] === 'unconfirmed';

// Manual statements: records linked to them by hand.
$usage = statement_usage($id);

$pageTitle = 'Statement Details';
$activeNav = 'statements';
require APP_ROOT . '/includes/header.php';

$missingButtons = static function () use ($missing): string {
    $html = '<div class="missing-actions no-print">';
    foreach ($missing as $label => [$href, $iconName]) {
        $html .= '<a data-modal-form href="' . e($href) . '" class="btn btn--sm btn--outline">' . icon($iconName) . ' <span>' . e($label) . '</span></a>';
    }

    return $html . '</div>';
};
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
        <p class="page-header__subtitle">
            <?php if ($statement['period_start_date']): ?>Period <?= e(format_date($statement['period_start_date'])) ?> – <?= e(format_date($statement['cutoff_date'])) ?> · <?php endif; ?>
            Statement <?= e(format_date($statement['statement_date'])) ?> · due <?= e(format_date($statement['due_date'])) ?>
            · <?= $generated ? '<span class="status status--info">Generated</span>' : '<span class="status status--muted">Entered by hand</span>' ?>
            <?= reconciliation_badge($recon['status']) ?>
        </p>
    </div>
    <div class="page-header__actions no-print">
        <a href="<?= e(url('pages/credit_cards/view.php', ['id' => $card['id']])) ?>" class="btn btn--outline"><?= icon('arrow-left') ?> <span>Card</span></a>
        <button type="button" class="btn btn--outline" data-print><?= icon('printer') ?> <span>Print</span></button>
        <a data-modal-form href="<?= e(url('pages/statements/edit.php', ['id' => $id])) ?>" class="btn btn--outline"><?= icon('edit') ?> <span>Edit</span></a>
        <a data-modal-form href="<?= e(url('pages/statements/reconcile.php', ['id' => $id])) ?>" class="btn btn--primary"><?= icon('scale') ?> <span>Enter bank SOA</span></a>
    </div>
</section>

<?php /* ---------------- Reconciliation result ---------------- */ ?>
<?php if ($recon['status'] === 'pending'): ?>
    <div class="recon-result recon-result--pending" role="status">
        <h3>Pending review</h3>
        <p>When the bank's Statement of Account arrives, click <strong>Enter bank SOA</strong> and type its statement balance (total amount due).
            It is compared with the statement balance calculated from your records below.</p>
    </div>
<?php else: $latest = $recon['latest']; ?>
    <div class="recon-result recon-result--<?= e($recon['status']) ?>" role="status">
        <?php if ($recon['status'] === 'balanced'): ?>
            <h3><?= icon('check') ?> Balanced</h3>
            <p>The bank's SOA statement balance matches the statement balance calculated from your records.</p>
        <?php else: ?>
            <h3><?= icon('alert') ?> Difference detected — review transactions and fees</h3>
        <?php endif; ?>
        <dl class="recon-figures">
            <div><dt>Bank SOA statement balance</dt><dd><?= e(format_money($latest['bank_statement_balance'])) ?></dd></div>
            <div><dt>System-calculated statement balance</dt><dd><?= e(format_money($statement['total_amount_due'])) ?></dd></div>
            <div><dt>Difference (bank − system)</dt><dd class="<?= $recon['status'] === 'balanced' ? '' : 'text-danger' ?>"><?= e(format_money($recon['difference'])) ?></dd></div>
            <div><dt>Status</dt><dd><?= reconciliation_badge($recon['status']) ?></dd></div>
        </dl>
        <?php $comparison = reconciliation_comparison($latest, $statement); if (count($comparison) > 1): ?>
            <div class="table-wrap">
                <table class="table table--compact recon-compare">
                    <thead><tr><th scope="col">SOA figure</th><th scope="col" class="num">Bank</th><th scope="col" class="num">System</th><th scope="col" class="num">Difference</th></tr></thead>
                    <tbody>
                    <?php foreach ($comparison as [$label, $bank, $system, $diff]): ?>
                        <tr>
                            <td data-label="SOA figure"><?= e($label) ?></td>
                            <td data-label="Bank" class="num"><?= e(format_money($bank)) ?></td>
                            <td data-label="System" class="num"><?= $system === null ? '<span class="status status--warning">Needs confirmation</span>' : e(format_money($system)) ?></td>
                            <td data-label="Difference" class="num"><?= $diff === null ? '—' : (abs($diff) < 0.005 ? '<span class="status status--success">Match</span>' : '<strong class="text-danger">' . e(format_money($diff)) . '</strong>') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        <p class="recon-meta">SOA entered <?= e(format_datetime($latest['created_at'])) ?><?= $latest['notes'] ? ' · ' . e($latest['notes']) : '' ?>.
            <?php if ($recon['changed_since']): ?>
                Your records changed since then: the system balance was <?= e(format_money($latest['system_statement_balance'])) ?> and is now <?= e(format_money($statement['total_amount_due'])) ?>.
            <?php endif; ?></p>
        <?php if ($recon['status'] === 'difference'): ?>
            <p class="recon-help">Compare the records below with the bank's SOA. Nothing was changed automatically. If the SOA lists something you did not record
                (e.g. an annual fee), add it here — it is placed in the statement its date belongs to and the difference is recalculated.</p>
            <div class="recon-actions no-print">
                <a href="#review" class="btn btn--sm btn--primary"><?= icon('search') ?> <span>Review transactions</span></a>
            </div>
            <p class="recon-help no-print"><strong>Add missing transaction:</strong></p>
            <?= $missingButtons() ?>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php /* ---------------- Figures ---------------- */ ?>
<section class="figure-grid">
    <div class="figure"><p class="figure__label">Previous balance</p><p class="figure__value"><?= e(format_money($statement['previous_balance'])) ?></p></div>
    <div class="figure"><p class="figure__label">+ Charges</p><p class="figure__value"><?= e(format_money($charges)) ?></p></div>
    <div class="figure"><p class="figure__label">− Payments &amp; credits</p><p class="figure__value"><?= e(format_money($paidCredits)) ?></p></div>
    <div class="figure figure--total"><p class="figure__label">= Statement balance</p><p class="figure__value"><?= e(format_money($statement['total_amount_due'])) ?></p></div>
    <div class="figure"><p class="figure__label">Minimum amount due</p><p class="figure__value"><?= $minimumUnconfirmed ? '<span class="status status--warning">Needs confirmation</span>' : e(format_money($statement['minimum_amount_due'])) ?></p></div>
    <div class="figure"><p class="figure__label">Payment due date</p><p class="figure__value"><?= e(format_date($statement['due_date'])) ?><?= $dueUnconfirmed ? ' <span class="status status--warning">Needs confirmation</span>' : '' ?></p></div>
</section>

<?php if ($period && $period['warnings']): ?>
    <div class="alert alert--warning" role="status"><?= icon('alert') ?>
        <span><strong>Needs attention:</strong> <?= e(implode(' ', $period['warnings'])) ?></span></div>
<?php endif; ?>

<div class="detail-layout">
    <section class="panel">
        <header class="panel__header"><h3>Statement breakdown</h3></header>
        <?php
        $rows = [
            'Card'           => related_link($statement['credit_card_id'], $statement['card_nickname'] . ' •••• ' . $statement['last_four'], 'pages/credit_cards/view.php'),
            'Statement number' => show($statement['statement_number']),
            'Period'         => $statement['period_start_date'] ? e(format_date($statement['period_start_date']) . ' – ' . format_date($statement['cutoff_date'])) : show($statement['cutoff_date'] ? 'Cutoff ' . format_date($statement['cutoff_date']) : null),
            'Statement date' => e(format_date($statement['statement_date'])),
            'Payment due date' => e(format_date($statement['due_date'])) . ($generated ? ' <span class="text-muted">· ' . e(match ($statement['due_date_status']) { 'rule' => 'from the card\'s rule', 'manual' => 'entered', default => 'needs confirmation' }) . '</span>' : ''),
            'Previous balance' => e(format_money($statement['previous_balance'])),
        ];
        foreach (STATEMENT_PARTS as $part => $label) {
            $rows[$label] = (in_array($part, STATEMENT_CREDIT_PARTS, true) ? '− ' : '') . e(format_money($statement[$part]));
        }
        $rows['Adjustment'] = e(format_money($statement['adjustments'])) . ($statement['adjustment_reason'] ? ' <span class="text-muted">· ' . e($statement['adjustment_reason']) . '</span>' : '');
        $rows['Statement balance'] = '<strong>' . e(format_money($statement['total_amount_due'])) . '</strong>';
        $rows['Minimum amount due'] = $minimumUnconfirmed ? '<span class="status status--warning">Needs confirmation</span> <span class="text-muted">set the card\'s minimum-due rule or enter it on Edit</span>'
            : e(format_money($statement['minimum_amount_due'])) . ($generated ? ' <span class="text-muted">· ' . ($statement['minimum_due_status'] === 'rule' ? 'from the card\'s rule' : 'fixed — entered, or kept when the rule changed') . '</span>' : '');
        $rows['Statement file'] = related_link($statement['attachment_document_id'], $statement['original_filename'], 'pages/documents/view.php');
        $rows['Last calculated'] = e(format_datetime($statement['updated_at']));
        echo render_details($rows);
        ?>
    </section>

    <div class="detail-side">
        <section class="panel">
            <header class="panel__header"><h3>How this statement is made</h3></header>
            <?php if ($generated): ?>
                <p class="field-help">Generated at the cutoff from the card's records dated <?= e(format_date($statement['period_start_date'])) ?> – <?= e(format_date($statement['cutoff_date'])) ?>
                    (their own dates, not the day they were entered). It is recalculated whenever a record changes; its period never moves when the card's cutoff day changes.</p>
                <p class="field-help">Statement balance is not the card's current balance: <?= e(format_money(card_usage($card['id'])['used'])) ?> is used on the card today, including installments not billed yet.</p>
            <?php else: ?>
                <p class="field-help">Entered by hand: its figures are kept exactly as typed and are not recalculated.</p>
            <?php endif; ?>
        </section>
        <?php if (!$generated): ?>
            <section class="panel panel--danger no-print">
                <header class="panel__header"><h3>Delete</h3></header>
                <?php if ($usage['total'] > 0 || $history): ?>
                    <p class="field-help">Linked to <?= e($history ? 'SOA reconciliations' : usage_text($usage)) ?>, so it is kept.</p>
                <?php else: ?>
                    <p class="field-help">Delete only a statement entered by mistake.</p>
                    <?= post_button(url('pages/statements/delete.php'), ['id' => $id], 'Delete statement', 'trash', 'btn btn--danger btn--block',
                        'Are you sure you want to delete this statement? This cannot be undone.') ?>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </div>
</div>

<?php /* ---------------- Records in the statement ---------------- */ ?>
<?php if ($generated): ?>
    <section class="panel subpanel" id="review">
        <header class="panel__header">
            <h3><?= icon('layers') ?> Transactions in this statement (<?= e(count($period['lines'])) ?>)</h3>
        </header>
        <p class="field-help">Tick these off against the bank's SOA. Missing on your side? Add it below — it goes to the statement its date belongs to.
            Wrong here? Edit the record; the statement and its reconciliation are recalculated (every change is kept in the activity log).</p>
        <?= $missingButtons() ?>
        <?php if (!$period['lines'] && $period['opening'] == 0): ?>
            <p class="empty-inline">No records dated in this period.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table table--cards table--compact">
                    <thead><tr><th scope="col">Date</th><th scope="col">Type</th><th scope="col">Description</th><th scope="col" class="num">Amount</th><th scope="col" class="actions no-print"><span class="visually-hidden">Actions</span></th></tr></thead>
                    <tbody>
                    <?php if ($period['opening'] > 0): ?>
                        <tr><td data-label="Date">—</td><td data-label="Type">Opening balance</td><td data-label="Description">Balance before the card was tracked (in the previous balance)</td>
                            <td data-label="Amount" class="num"><?= e(format_money($period['opening'])) ?></td><td></td></tr>
                    <?php endif; ?>
                    <?php foreach ($period['lines'] as $l): $credit = in_array($l['part'], STATEMENT_CREDIT_PARTS, true); $edit = statement_line_edit_url($l); ?>
                        <tr>
                            <td data-label="Date"><?= e(format_date($l['date'])) ?></td>
                            <td data-label="Type"><?= e(STATEMENT_PARTS[$l['part']]) ?></td>
                            <td data-label="Description"><a href="<?= e($l['link']) ?>"><?= e($l['description']) ?></a>
                                <?php if ($l['note']): ?><br><span class="text-muted"><?= e($l['note']) ?></span><?php endif; ?></td>
                            <td data-label="Amount" class="num"><?= $credit ? '− ' : '' ?><?= e(format_money($l['amount'])) ?></td>
                            <td class="actions no-print"><div class="row-actions">
                                <?php if ($edit): ?><?= icon_link($edit, 'edit', 'Edit ' . $l['description']) ?><?php endif; ?>
                            </div></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php /* ---------------- Reconciliation history ---------------- */ ?>
<section class="panel subpanel">
    <header class="panel__header"><h3><?= icon('history') ?> SOA reconciliation history</h3></header>
    <?php if (!$history): ?>
        <p class="empty-inline">No bank SOA entered yet.</p>
    <?php else: ?>
        <p class="field-help">Each entry keeps the bank's figures and what the system calculated at that moment.</p>
        <div class="table-wrap">
            <table class="table table--cards table--compact">
                <thead><tr><th scope="col">Entered</th><th scope="col" class="num">Bank balance</th><th scope="col" class="num">System then</th><th scope="col" class="num">Difference then</th>
                    <th scope="col" class="num">Bank minimum</th><th scope="col">Result then</th><th scope="col">Notes</th></tr></thead>
                <tbody>
                <?php foreach ($history as $h): ?>
                    <tr>
                        <td data-label="Entered"><?= e(format_datetime($h['created_at'])) ?><br><span class="text-muted"><?= e($h['user_name'] ?? '') ?></span></td>
                        <td data-label="Bank balance" class="num"><?= e(format_money($h['bank_statement_balance'])) ?></td>
                        <td data-label="System then" class="num"><?= e(format_money($h['system_statement_balance'])) ?></td>
                        <td data-label="Difference then" class="num"><?= e(format_money($h['difference'])) ?></td>
                        <td data-label="Bank minimum" class="num"><?= $h['bank_minimum_due'] === null ? '—' : e(format_money($h['bank_minimum_due'])) ?></td>
                        <td data-label="Result then"><?= reconciliation_badge($h['status']) ?></td>
                        <td data-label="Notes"><?= show($h['notes']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php require APP_ROOT . '/includes/footer.php'; ?>
