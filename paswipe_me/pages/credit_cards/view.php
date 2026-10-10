<?php
/**
 * Credit Cards - card details, utilization, billing cycles, recent activity.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/credit_card_functions.php';

$currentUser = require_admin();

$card = find_or_redirect(credit_card_find(request_id()), 'pages/credit_cards/index.php', 'Credit card');
$id = $card['id'];

// The billing period open today and its records so far (read only).
$openPeriod = card_period_containing(card_period_context($card), date('Y-m-d'));
$openSummary = statement_lines_summary(statement_period_lines($id, $openPeriod['start'], $openPeriod['end']));
$cardUsage = card_usage($id);
$cashLoans = db_all(
    'SELECT * FROM cash_loans WHERE credit_card_id = :id ORDER BY created_at DESC LIMIT 8',
    [':id' => $id]
);

$cycles = db_all('SELECT * FROM card_billing_cycles WHERE credit_card_id = :id ORDER BY cycle_start_date DESC', [':id' => $id]);
$transactions = db_all(
    'SELECT t.id, t.transaction_number, t.transaction_date, t.amount, t.status, m.merchant_name
       FROM transactions t LEFT JOIN merchants m ON m.id = t.merchant_id
      WHERE t.credit_card_id = :id ORDER BY t.transaction_date DESC LIMIT 8',
    [':id' => $id]
);
$statements = db_all(
    'SELECT s.id, s.statement_date, s.cutoff_date, s.due_date, s.total_amount_due, s.minimum_amount_due,
            COALESCE(sp.paid, 0) AS paid, ' . statement_remaining_sql() . ' AS remaining, ' . statement_status_sql() . ' AS pay_status
       FROM credit_card_statements s ' . statement_paid_join() . '
      WHERE s.credit_card_id = :id AND s.soa_recorded_at IS NOT NULL ORDER BY COALESCE(s.cutoff_date, s.statement_date) DESC LIMIT 6',
    [':id' => $id]
);
$statementPeriods = db_all('SELECT period_start_date, cutoff_date FROM credit_card_statements WHERE credit_card_id = :id', [':id' => $id]);
$usage = credit_card_usage($id);
$bankRules = array_values(array_filter(
    db_all("SELECT * FROM penalty_rules WHERE is_active = 1 AND applies_to_issuer IS NOT NULL ORDER BY name"),
    static fn ($r) => same_bank($r['applies_to_issuer'], $card['issuer'])
));
$related = url('pages/credit_cards/related.php');

$pageTitle = 'Credit Card Details';
$activeNav = 'credit_cards';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb(['Credit Cards' => url('pages/credit_cards/index.php'), credit_card_label($card) => null]) ?>

<div class="print-header">
    <p class="print-header__system"><?= e(APP_NAME) ?></p>
    <h2 class="print-header__title">Credit Card Record</h2>
    <p class="print-header__meta">Printed: <?= e(date('F j, Y g:i A')) ?></p>
</div>

<section class="page-header">
    <div>
        <h2 class="page-header__title"><?= e($card['card_nickname']) ?></h2>
        <p class="page-header__subtitle"><?= e($card['issuer']) ?> •••• <?= e($card['last_four']) ?> · <?= badge($card['status']) ?></p>
    </div>
    <div class="page-header__actions no-print">
        <a href="<?= e(url('pages/credit_cards/index.php')) ?>" class="btn btn--outline"><?= icon('arrow-left') ?> <span>Back</span></a>
        <button type="button" class="btn btn--outline" data-print><?= icon('printer') ?> <span>Print</span></button>
        <a data-modal-form href="<?= e(url('pages/credit_cards/edit.php', ['id' => $id])) ?>" class="btn btn--primary"><?= icon('edit') ?> <span>Edit</span></a>
    </div>
</section>

<section class="figure-grid" aria-label="Card figures">
    <?= render_card_usage_figures($cardUsage) ?>
</section>

<div class="detail-layout">
    <section class="panel">
        <header class="panel__header"><h3>Card details</h3></header>
        <?= render_details([
            'Nickname'          => e($card['card_nickname']),
            'Issuer'            => e($card['issuer']),
            'Owner'             => show($card['owner_name']),
            'Card type'         => e(humanize($card['card_type'])),
            'Network'           => show($card['network'] ? strtoupper($card['network']) : null),
            'Last four digits'  => e($card['last_four']),
            'Interest rate'     => show($card['interest_rate'] !== null ? $card['interest_rate'] . '%' : null),
            'Statement cutoff day' => show($card['billing_cycle_day'] ? ordinal((int) $card['billing_cycle_day']) . ' of the month' : null),
            'Payment due date'  => e(card_billing_summary($card)),
            'Minimum due rule'  => card_has_minimum_rule($card)
                ? e(rtrim(rtrim(number_format((float) $card['minimum_due_percent'], 4), '0'), '.') . '% of the statement balance, at least ' . format_money($card['minimum_due_floor']) . ' (reference)')
                : '<span class="text-muted">Not set (the minimum due comes from each SOA)</span>',
            'Annual fee'        => (int) $card['annual_fee'] === 1 ? e(format_money($card['annual_fee_amount'])) : 'None',
            'Opening date'      => show($card['opening_date'] ? format_date($card['opening_date']) : null),
            'Expiration date'   => show($card['expiration_date'] ? format_date($card['expiration_date']) : null),
            'Status'            => badge($card['status']),
            'Notes'             => show_multiline($card['notes']),
            'Created'           => e(format_datetime($card['created_at'])),
            'Last updated'      => e(format_datetime($card['updated_at'])),
        ]) ?>
    </section>

    <div class="detail-side">
        <section class="panel">
            <header class="panel__header"><h3>Credit card summary</h3></header>
            <dl class="usage-breakdown">
                <div><dt>Credit limit</dt><dd><?= e(format_money($cardUsage['credit_limit'])) ?></dd></div>
                <?php foreach (CARD_USAGE_PARTS as $part => $label): ?>
                    <?php if ($part === 'opening_balance' && $cardUsage[$part] == 0) { continue; } ?>
                    <?php $minus = in_array($part, ['payments', 'credits'], true); ?>
                    <div class="<?= $minus ? 'is-minus' : '' ?>"><dt><?= $minus ? '− ' : '+ ' ?><?= e($label) ?></dt><dd><?= e(format_money($cardUsage[$part])) ?></dd></div>
                <?php endforeach; ?>
                <div class="is-total"><dt>Current used credit</dt><dd><?= e(format_money($cardUsage['used'])) ?></dd></div>
                <div><dt>Available credit</dt><dd class="<?= $cardUsage['over_limit'] ? 'text-danger' : '' ?>"><?= e(format_money($cardUsage['available'])) ?></dd></div>
                <div><dt>Utilization</dt><dd><?= e(number_format($cardUsage['utilization'], 2)) ?>%</dd></div>
            </dl>
            <p class="field-help">Calculated from this card's posted transactions, cash loans (accepted, disbursed, active, completed), cash advances, bank charges and card payments. Drafts, offers, cancelled and rejected records are not counted.</p>
        </section>

        <section class="panel">
            <header class="panel__header">
                <h3>Cash loans on this card</h3>
                <a data-modal-form class="btn btn--sm btn--outline no-print" href="<?= e(url('pages/cash_loans/create.php', ['credit_card_id' => $id])) ?>"><?= icon('plus') ?> <span>Add</span></a>
            </header>
            <?php if (!$cashLoans): ?>
                <p class="empty-inline">No cash loans on this card.</p>
            <?php else: ?>
                <ul class="child-list">
                    <?php foreach ($cashLoans as $l): ?>
                        <?php $charge = cash_loan_card_charge($l); ?>
                        <li><a href="<?= e(url('pages/cash_loans/view.php', ['id' => $l['id']])) ?>"><?= e($l['loan_reference']) ?></a>
                            <span><?= $charge['counts'] ? e(format_money($charge['total'])) . ' charged' : 'Not charged' ?> <?= badge($l['status']) ?></span></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <section class="panel">
            <header class="panel__header">
                <h3>Penalty rules for <?= e(bank_canonical($card['issuer'])) ?></h3>
                <a class="btn btn--sm btn--outline no-print" href="<?= e(url('pages/penalty_rules/index.php')) ?>"><span>Manage</span></a>
            </header>
            <?php if (!$bankRules): ?>
                <p class="empty-inline">No active penalty rule for this bank. Borrowers' items from this card are not charged automatically.</p>
            <?php else: ?>
                <ul class="child-list">
                    <?php foreach ($bankRules as $r): ?>
                        <li><a href="<?= e(url('pages/penalty_rules/view.php', ['id' => $r['id']])) ?>"><?= e($r['name']) ?></a><span><?= e(penalty_rule_summary($r)) ?></span></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <section class="panel">
            <header class="panel__header">
                <h3>Statements</h3>
                <a class="btn btn--sm btn--outline no-print" href="<?= e(url('pages/statements/index.php', ['card' => $id])) ?>"><span>View all</span></a>
            </header>
            <p class="field-help"><?= e(card_billing_summary($card)) ?></p>
            <div class="open-cycle">
                <p class="open-cycle__title">Current cycle <?= e(format_date($openPeriod['start'])) ?> – <?= e(format_date($openPeriod['end'])) ?></p>
                <p class="text-muted">Charges so far <?= e(format_money($openSummary['charges'])) ?> · payments and credits <?= e(format_money($openSummary['payments'])) ?>
                    · <?= e($openSummary['count']) ?> record(s).</p>
            </div>
            <?php if (!$statements): ?>
                <p class="empty-inline">No bank SOA recorded yet.</p>
            <?php else: ?>
                <ul class="child-list">
                    <?php foreach ($statements as $s): ?>
                        <li><span><a data-modal-form href="<?= e(url('pages/statements/view.php', ['id' => $s['id']])) ?>"><?= e(statement_month_label($s['cutoff_date'] ?: $s['statement_date'])) ?></a>
                                <br><?= statement_status_badge($s['pay_status'], $s['due_date']) ?></span>
                            <span><?= e(format_money($s['total_amount_due'])) ?> · due <?= e(format_date($s['due_date'])) ?>
                                <?php if ((float) $s['remaining'] > 0.004): ?>
                                    <a data-modal-form class="btn btn--sm btn--primary no-print" href="<?= e(url('pages/statements/pay.php', ['id' => $s['id']])) ?>"><?= icon('wallet') ?> <span>Pay</span></a>
                                <?php endif; ?></span></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <p class="field-help no-print"><a data-modal-form href="<?= e(url('pages/statements/create.php', ['credit_card_id' => $id])) ?>">Record an SOA</a> · <a href="<?= e(url('pages/statements/index.php', ['card' => $id])) ?>">All billing periods</a></p>
        </section>

        <section class="panel panel--danger no-print">
            <header class="panel__header"><h3>Delete or close</h3></header>
            <?php if ($usage['total'] > 0): ?>
                <p class="field-help">This card has <?= e(usage_text($usage)) ?>, so it must be kept. Change its status (closed, blocked, inactive) instead.</p>
                <?php if ($card['status'] === 'active'): ?>
                    <?= post_button(url('pages/credit_cards/delete.php'), ['id' => $id, 'action' => 'deactivate'], 'Mark as inactive', 'power', 'btn btn--warning btn--block',
                        'Mark this card as inactive? Its history is kept.', false, 'Deactivate card?') ?>
                <?php endif; ?>
            <?php else: ?>
                <p class="field-help">No transactions or statements yet. Deleting also removes its billing cycles.</p>
                <?= post_button(url('pages/credit_cards/delete.php'), ['id' => $id, 'action' => 'delete'], 'Delete card', 'trash', 'btn btn--danger btn--block',
                    'Are you sure you want to delete this card? This cannot be undone.') ?>
            <?php endif; ?>
        </section>
    </div>
</div>

<section class="panel subpanel">
    <header class="panel__header">
        <h3>Billing cycles</h3>
        <button type="button" class="btn btn--sm btn--outline no-print" data-modal-panel="add-cycle" data-modal-subtitle="Only when the bank uses a different schedule for an upcoming period. Normal periods follow the card's cutoff day."><?= icon('plus') ?> <span>Add special cycle</span></button>
    </header>
    <p class="field-help">Periods follow the statement cutoff day set on the card. A recorded SOA keeps its own period.</p>
    <form method="post" action="<?= e($related) ?>" class="inline-panel no-print" id="add-cycle" hidden novalidate data-validate>
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= e($id) ?>">
        <input type="hidden" name="action" value="add_cycle">
        <?= render_fields(billing_cycle_fields(), [], []) ?>
        <div class="inline-panel__actions">
            <button type="button" class="btn btn--outline" data-toggle-panel="add-cycle">Cancel</button>
            <button type="submit" class="btn btn--primary" data-loading-text="Saving…"><?= icon('check') ?> <span class="btn__label">Save cycle</span></button>
        </div>
    </form>
    <?php if (!$cycles): ?>
        <p class="empty-inline">No billing cycles yet. Set the statement cutoff day on the card (Edit) and they are created at each cutoff.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table--cards table--compact">
                <thead><tr><th scope="col">Cycle</th><th scope="col">Statement date</th><th scope="col">Payment due</th><th scope="col" class="actions no-print"><span class="visually-hidden">Actions</span></th></tr></thead>
                <tbody>
                <?php foreach ($cycles as $c): ?>
                    <tr>
                        <td data-label="Cycle"><?= e(format_date($c['cycle_start_date'])) ?> – <?= e(format_date($c['cycle_end_date'])) ?></td>
                        <td data-label="Statement date"><?= e(format_date($c['statement_date'])) ?></td>
                        <td data-label="Payment due"><?= e(format_date($c['payment_due_date'])) ?></td>
                        <td class="actions no-print"><div class="row-actions">
                            <?php $hasStatement = (bool) array_filter($statementPeriods, static fn ($p) => $p['cutoff_date'] === $c['cycle_end_date'] && ($p['period_start_date'] ?? $c['cycle_start_date']) === $c['cycle_start_date']); ?>
                            <?php if ($hasStatement): ?>
                                <span class="text-muted">Statement</span>
                            <?php else: ?>
                                <?= post_button($related, ['id' => $id, 'action' => 'delete_cycle', 'item_id' => $c['id']], 'Delete cycle', 'trash', 'icon-button icon-button--danger', 'Delete this billing cycle?', true) ?>
                            <?php endif; ?>
                        </div></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="panel subpanel">
    <header class="panel__header">
        <h3>Recent transactions</h3>
        <a class="btn btn--sm btn--outline no-print" href="<?= e(url('pages/transactions/index.php', ['card' => $id])) ?>"><span>View all</span></a>
    </header>
    <?php if (!$transactions): ?>
        <p class="empty-inline">No transactions on this card.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table--cards table--compact">
                <thead><tr><th scope="col">Transaction #</th><th scope="col">Date</th><th scope="col">Merchant</th><th scope="col" class="num">Amount</th><th scope="col">Status</th></tr></thead>
                <tbody>
                <?php foreach ($transactions as $t): ?>
                    <tr>
                        <td data-label="Transaction #"><a href="<?= e(url('pages/transactions/view.php', ['id' => $t['id']])) ?>"><?= e($t['transaction_number']) ?></a></td>
                        <td data-label="Date"><?= e(format_date($t['transaction_date'])) ?></td>
                        <td data-label="Merchant"><?= show($t['merchant_name']) ?></td>
                        <td data-label="Amount" class="num"><?= e(format_money($t['amount'])) ?></td>
                        <td data-label="Status"><?= badge($t['status']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php require APP_ROOT . '/includes/footer.php'; ?>
