<?php
/**
 * Earnings (card owner): the interest your borrowers pay you on top of the
 * bank's amounts ("Your rate to the borrower" on a lent purchase), counted
 * only when they pay - by month and by purchase - and your saving goals
 * (e.g. a card's annual fee). See includes/earnings.php.
 */

require_once __DIR__ . '/../../includes/auth_check.php';

$currentUser = require_admin();
earnings_schema_upgrade();

$cards = credit_card_options();
$cardId = input_string($_GET, 'card');
if ($cardId !== '' && !isset($cards[$cardId])) {
    $cardId = '';
}

$purchases = earnings_purchases($cardId !== '' ? $cardId : null);
$byMonth = earnings_by_month($purchases);
$expected = round(array_sum(array_column($purchases, 'interest')), 2);
$earned = round(array_sum(array_column($purchases, 'earned')), 2);
$thisMonth = $byMonth[date('Y-m')] ?? 0.0;
$thisYear = round(array_sum(array_filter($byMonth, static fn ($v, $k) => str_starts_with($k, date('Y')), ARRAY_FILTER_USE_BOTH)), 2);
$goals = savings_goals_with_progress($cardId !== '' ? $purchases : earnings_purchases());

$pageTitle = 'Earnings';
$activeNav = 'earnings';
require APP_ROOT . '/includes/header.php';
?>

<div class="print-header">
    <p class="print-header__system"><?= e(APP_NAME) ?></p>
    <h2 class="print-header__title">Earnings</h2>
    <p class="print-header__meta">Printed: <?= e(date('F j, Y g:i A')) ?><?= $cardId !== '' ? ' · ' . e($cards[$cardId]) : '' ?></p>
</div>

<section class="page-header">
    <div>
        <h2 class="page-header__title">Earnings</h2>
        <p class="page-header__subtitle">Your interest on purchases lent to borrowers (your rate, on top of the bank's amounts). It counts only when the borrower pays.</p>
    </div>
    <div class="page-header__actions no-print">
        <button type="button" class="btn btn--outline" data-print><?= icon('printer') ?> <span>Print</span></button>
        <a data-modal-form href="<?= e(url('pages/earnings/goal.php')) ?>" class="btn btn--primary"><?= icon('plus') ?> <span>Add saving goal</span></a>
    </div>
</section>

<form method="get" action="<?= e(url('pages/earnings/index.php')) ?>" class="panel toolbar no-print">
    <div class="toolbar__filters">
        <label class="visually-hidden" for="f_card">Card</label>
        <select id="f_card" name="card" data-auto-submit>
            <option value="">All cards</option>
            <?php foreach ($cards as $id => $label): ?>
                <option value="<?= e($id) ?>"<?= $id === $cardId ? ' selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn--primary"><?= icon('search') ?> <span>Show</span></button>
    </div>
</form>

<section class="figure-grid" aria-label="Earnings">
    <div class="figure"><p class="figure__label">Earned (received)</p><p class="figure__value text-success"><?= e(format_money($earned)) ?></p></div>
    <div class="figure"><p class="figure__label">Still to receive</p><p class="figure__value"><?= e(format_money(max(0, $expected - $earned))) ?></p></div>
    <div class="figure"><p class="figure__label">Total agreed interest</p><p class="figure__value"><?= e(format_money($expected)) ?></p></div>
    <div class="figure"><p class="figure__label">This month</p><p class="figure__value"><?= e(format_money($thisMonth)) ?></p></div>
    <div class="figure"><p class="figure__label"><?= e(date('Y')) ?> so far</p><p class="figure__value"><?= e(format_money($thisYear)) ?></p></div>
</section>

<section class="panel subpanel">
    <header class="panel__header"><h3><?= icon('banknote') ?> Saving goals</h3></header>
    <?php if (!$goals): ?>
        <p class="text-muted">No goals yet. Add one (e.g. "BDO annual fee ₱4,500") and the interest your borrowers pay counts toward it.</p>
    <?php else: ?>
        <div class="goal-list">
            <?php foreach ($goals as $g): ?>
                <article class="goal<?= $g['status'] !== 'active' ? ' goal--done' : '' ?>">
                    <div class="goal__head">
                        <div>
                            <strong><?= e($g['name']) ?></strong> <?= status_badge(SAVINGS_GOAL_STATUSES[$g['status']] ?? $g['status'], ['Saving' => 'info', 'Used' => 'success', 'Cancelled' => 'muted']) ?>
                            <p class="text-muted"><?= $g['credit_card_id'] ? e($g['card_nickname'] . ' •••• ' . $g['last_four']) : 'All cards' ?> · from <?= e(format_date($g['start_date'])) ?><?= $g['target_date'] ? ' · needed by ' . e(format_date($g['target_date'])) : '' ?></p>
                        </div>
                        <div class="goal__actions no-print">
                            <?php if ($g['status'] === 'active'): ?>
                                <?= post_button(url('pages/earnings/goal_action.php'), ['id' => $g['id'], 'action' => 'used'], 'Mark used', 'check', 'btn btn--sm btn--outline',
                                    'Mark "' . $g['name'] . '" as used (e.g. the annual fee was paid)? It stays in the list.', false, 'Mark goal used?') ?>
                            <?php endif; ?>
                            <a data-modal-form href="<?= e(url('pages/earnings/goal.php', ['id' => $g['id']])) ?>" class="icon-button" title="Edit goal" aria-label="Edit goal"><?= icon('edit') ?></a>
                            <?= post_button(url('pages/earnings/goal_action.php'), ['id' => $g['id'], 'action' => 'delete'], 'Delete goal', 'trash', 'icon-button icon-button--danger',
                                'Delete the goal "' . $g['name'] . '"? Your earnings are not affected.', true, 'Delete goal?') ?>
                        </div>
                    </div>
                    <div class="goal__bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= e($g['percent']) ?>"><span style="width: <?= e($g['percent']) ?>%"></span></div>
                    <p><strong><?= e(format_money($g['saved'])) ?></strong> of <?= e(format_money($g['target_amount'])) ?> saved (<?= e($g['percent']) ?>%)
                        <?php if ($g['saved'] < (float) $g['target_amount']): ?><span class="text-muted">· <?= e(format_money((float) $g['target_amount'] - $g['saved'])) ?> to go</span><?php endif; ?></p>
                </article>
            <?php endforeach; ?>
        </div>
        <p class="field-help">A goal only keeps track: it counts the interest received on the chosen card from its start date. No money is moved.</p>
    <?php endif; ?>
</section>

<div class="detail-layout">
    <section class="panel panel--flush">
        <header class="panel__header panel__header--padded"><h3><?= icon('layers') ?> By purchase</h3></header>
        <?php if (!$purchases): ?>
            <p class="text-muted panel__body">No lent purchase has your rate yet. Add it on the purchase: Transactions → Edit → "Your rate to the borrower (% per month)".</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table table--cards table--compact">
                    <thead><tr><th scope="col">Purchase</th><th scope="col">Borrower</th><th scope="col">Rate</th>
                        <th scope="col" class="num">Your interest</th><th scope="col" class="num">Received</th><th scope="col" class="num">To receive</th></tr></thead>
                    <tbody>
                    <?php foreach ($purchases as $p): ?>
                        <tr>
                            <td data-label="Purchase"><a data-modal-form class="table-link" href="<?= e(url('pages/transactions/view.php', ['id' => $p['id']])) ?>"><?= e($p['transaction_number']) ?></a>
                                <br><span class="text-muted"><?= e(($p['merchant_name'] ?: 'Purchase') . ' · ' . $p['card_nickname'] . ' •••• ' . $p['last_four'] . ' · ' . format_money($p['amount'])) ?></span></td>
                            <td data-label="Borrower"><?= e(trim($p['first_name'] . ' ' . $p['last_name'])) ?></td>
                            <td data-label="Rate"><?= e(borrower_rate_text((float) $p['ledger']['rate'])) ?><br><span class="text-muted"><?= e(max(1, $p['ledger']['installments']) . ' month(s)') ?></span></td>
                            <td data-label="Your interest" class="num"><?= e(format_money($p['interest'])) ?></td>
                            <td data-label="Received" class="num text-success"><?= e(format_money($p['earned'])) ?></td>
                            <td data-label="To receive" class="num"><?= e(format_money($p['to_receive'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot><tr><th scope="row" colspan="3">Total</th><td class="num"><strong><?= e(format_money($expected)) ?></strong></td>
                        <td class="num"><strong><?= e(format_money($earned)) ?></strong></td><td class="num"><strong><?= e(format_money(max(0, $expected - $earned))) ?></strong></td></tr></tfoot>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="panel panel--flush">
        <header class="panel__header panel__header--padded"><h3><?= icon('calendar') ?> Received by month</h3></header>
        <?php if (!$byMonth): ?>
            <p class="text-muted panel__body">Nothing received yet.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table table--compact">
                    <thead><tr><th scope="col">Month</th><th scope="col" class="num">Interest received</th></tr></thead>
                    <tbody>
                    <?php foreach ($byMonth as $month => $amount): ?>
                        <tr><td><?= e(date('F Y', strtotime($month . '-01'))) ?></td><td class="num"><?= e(format_money($amount)) ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        <p class="field-help panel__body">Each repayment pays the bank's part of an installment first; what is paid beyond it is your interest, counted in the month it was paid.</p>
    </section>
</div>

<?php require APP_ROOT . '/includes/footer.php'; ?>
