<?php
/**
 * Penalties panel of a purchase (or cash advance) the borrower repays to the
 * owner, tracked on its transaction: rules for the card's bank, Check
 * penalties now, Apply penalty, waive / delete. Included by
 * pages/transactions/view.php and pages/cash_advances/view.php; the actions
 * post to pages/transactions/related.php, which checks everything again.
 *
 * Needs: $txn (the transaction row), $purchase (borrowed_owner_purchases row
 * with its ledger, or null), $canManage, $isLocked.
 */

defined('APP_ROOT') or exit('No direct access.');

require_once APP_ROOT . '/pages/obligations/obligation_functions.php';   // penalty form fields
$penaltyTxnId = $txn['id'];
$penaltyAction = url('pages/transactions/related.php');
$penaltyBack = $penaltyBack ?? ('pages/transactions/view.php?id=' . $penaltyTxnId);   // where the actions return
$penaltyPaid = $purchase && $purchase['ledger']['outstanding'] <= 0.004;   // no new penalties once paid
$txnPenalties = [];
if ($purchase && $purchase['ledger']['mode'] === 'transaction') {
    $txnPenalties = db_all(
        'SELECT ap.*, r.name AS rule_name FROM applied_penalties ap JOIN penalty_rules r ON r.id = ap.penalty_rule_id
          WHERE ap.transaction_id = :id ORDER BY ap.applied_date',
        [':id' => $penaltyTxnId]
    );
    $txnRules = borrowed_rules_for_card($txn['credit_card_id']);
    $txnBank = bank_canonical((string) db_value('SELECT issuer FROM credit_cards WHERE id = :c', [':c' => $txn['credit_card_id']]));
    $txnTerminable = array_filter($txnPenalties, static fn ($p) => (float) $p['remaining_amount'] > 0.004 || (float) $p['paid_amount'] <= 0.004);
    $firstOverdue = null;
    foreach ($purchase['ledger']['rows'] as $row) {
        if ($row['overdue'] && $row['due']['date']) {
            $firstOverdue = $row['due']['date'];
            break;
        }
    }
    $txnPenaltyDefaults = form_defaults(penalty_fields(), [
        'calculation_base' => number_format((float) ($purchase['ledger']['overdue_amount'] ?: $purchase['ledger']['remaining']), 2, '.', ''),
        'overdue_days' => $firstOverdue ? max(0, (int) ((strtotime(date('Y-m-d')) - strtotime($firstOverdue)) / 86400)) : 0,
    ]);
}

?>
<?php if ($purchase && $purchase['ledger']['mode'] === 'transaction'): ?>
<section class="panel subpanel" id="penalties">
    <header class="panel__header">
        <h3><?= icon('scale') ?> Penalties</h3>
        <?php if ($canManage && !$isLocked && !$penaltyPaid): ?>
            <div class="action-bar no-print">
                <?= post_button($penaltyAction, ['id' => $penaltyTxnId, 'action' => 'run_penalties', 'back' => $penaltyBack], 'Check penalties now', 'repeat', 'btn btn--sm btn--outline') ?>
                <button type="button" class="btn btn--sm btn--outline" data-modal-panel="add-txn-penalty"><?= icon('plus') ?> <span>Apply penalty</span></button>
            </div>
        <?php endif; ?>
    </header>
    <div class="penalty-rules-note">
        <strong>Penalty rules assigned</strong> (active rules for <?= e($txnBank ?: 'this card\'s bank') ?>):
        <?php if (!$txnRules): ?>
            <span class="text-muted">none — <a href="<?= e(url('pages/penalty_rules/index.php')) ?>">set a rule's Bank to this card's bank</a>.</span>
        <?php else: ?>
            <ul>
                <?php foreach ($txnRules as $r): ?>
                    <li><?= e($r['name']) ?> — <?= e(penalty_rule_summary($r)) ?> · charges from <?= e(format_date($r['charges_from'])) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
    <?php if ($penaltyPaid): ?>
        <p class="field-help no-print">Paid in full: no new penalty can be added. Existing penalties can still be waived or deleted.</p>
    <?php endif; ?>
    <?php if ($canManage && !$isLocked && !$penaltyPaid): ?>
        <form method="post" action="<?= e($penaltyAction) ?>" class="inline-panel no-print" id="add-txn-penalty" hidden novalidate data-validate>
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= e($penaltyTxnId) ?>">
            <input type="hidden" name="action" value="add_penalty">
            <input type="hidden" name="back" value="<?= e($penaltyBack) ?>">
            <?php if (!penalty_rule_options()): ?>
                <p class="field-help">No active penalty rules. <a data-modal-form href="<?= e(url('pages/penalty_rules/create.php')) ?>">Create a rule</a> first.</p>
            <?php else: ?>
                <?= render_fields(penalty_fields(), $txnPenaltyDefaults, []) ?>
                <div class="inline-panel__actions">
                    <button type="button" class="btn btn--outline" data-toggle-panel="add-txn-penalty">Cancel</button>
                    <button type="submit" class="btn btn--primary" data-loading-text="Saving…"><?= icon('check') ?> <span class="btn__label">Apply penalty</span></button>
                </div>
            <?php endif; ?>
        </form>
    <?php endif; ?>
    <?php if (!$txnPenalties): ?>
        <p class="empty-inline">No penalties applied.</p>
    <?php else: ?>
        <form method="post" action="<?= e($penaltyAction) ?>" id="terminate-txn-penalty" data-bulk-form novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= e($penaltyTxnId) ?>">
            <input type="hidden" name="action" value="terminate_penalties">
            <input type="hidden" name="back" value="<?= e($penaltyBack) ?>">
            <div class="table-wrap">
                <table class="table table--cards table--compact">
                    <thead><tr>
                        <th scope="col" class="col-check no-print"><?php if ($canManage && $txnTerminable): ?><input type="checkbox" data-check-all aria-label="Select all penalties that can be waived or deleted"><?php endif; ?></th>
                        <th scope="col">Date</th><th scope="col">Rule</th><th scope="col" class="num">Days late</th><th scope="col" class="num">Base</th><th scope="col" class="num">Amount</th><th scope="col" class="num">Paid</th><th scope="col" class="num">Remaining</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($txnPenalties as $k => $p): ?>
                        <tr>
                            <td class="col-check no-print">
                                <?php if ($canManage && isset($txnTerminable[$k])): ?>
                                    <input type="checkbox" name="item_ids[]" value="<?= e($p['id']) ?>" data-check-item aria-label="Select penalty of <?= e(format_date($p['applied_date'])) ?>, <?= e($p['rule_name']) ?>">
                                <?php endif; ?>
                            </td>
                            <td data-label="Date"><?= e(format_date($p['applied_date'])) ?></td>
                            <td data-label="Rule"><?= e($p['rule_name']) ?><?= $p['notes'] ? '<br><span class="text-muted">' . nl2br(e($p['notes'])) . '</span>' : '' ?></td>
                            <td data-label="Days late" class="num"><?= e($p['overdue_days']) ?></td>
                            <td data-label="Base" class="num"><?= e(format_money($p['calculation_base'])) ?></td>
                            <td data-label="Amount" class="num"><?= e(format_money($p['amount'])) ?></td>
                            <td data-label="Paid" class="num"><?= e(format_money($p['paid_amount'])) ?></td>
                            <td data-label="Remaining" class="num"><?= e(format_money($p['remaining_amount'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($canManage && $txnTerminable): ?>
                <div class="inline-panel bulk-panel no-print">
                    <p class="bulk-panel__count"><strong data-check-count>0</strong> selected · tick the penalties above (or the box in the header for all), then:</p>
                    <?= render_fields([
                        'mode'   => ['label' => 'Action', 'type' => 'select', 'options' => ['waive' => 'Waive selected', 'delete' => 'Delete selected'], 'required' => true,
                                     'help' => 'Waive keeps the records and sets what is unpaid to zero. Delete removes penalties that have nothing paid.'],
                        'reason' => ['label' => 'Reason (applies to all selected)', 'type' => 'textarea', 'rows' => 2, 'required' => true, 'max' => 1000,
                                     'placeholder' => 'e.g. Cash payment received offline but not yet recorded in the system',
                                     'help' => 'Required (at least ' . PENALTY_REASON_MIN . ' characters). Saved with each penalty and in the activity log. Owners and administrators only.'],
                    ], ['mode' => 'waive'], []) ?>
                    <div class="inline-panel__actions">
                        <button type="submit" class="btn btn--danger" data-check-submit data-loading-text="Saving…" disabled><?= icon('check') ?> <span class="btn__label">Apply to selected</span></button>
                    </div>
                </div>
            <?php endif; ?>
        </form>
    <?php endif; ?>
    <p class="field-help">Penalties are charged automatically every month while a repayment of this purchase is overdue (for an installment purchase: per overdue installment). Repayments go where the borrower chose (late charges or an installment); money without a choice pays the oldest installments first.</p>
</section>
<?php endif; ?>
