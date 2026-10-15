<?php
/**
 * Staff - credit card due dates: what each card's statement asks and when it
 * is due (includes/staff.php staff_due_dates). The statement cutoff and the
 * payment due date are shown separately; amounts appear only once the bank's
 * SOA is recorded - nothing is estimated or invented.
 */

require_once __DIR__ . '/../../includes/auth_check.php';

$currentUser = require_staff_area();

$cardId = input_string($_GET, 'card');
$cardId = owns('credit_cards', $cardId) ? $cardId : '';
$status = input_string($_GET, 'status');
$rows = staff_due_dates($cardId ?: null);
if (isset(DUE_STATUS_LABELS[$status])) {
    $rows = array_values(array_filter($rows, static fn ($r) => $r['status'] === $status));
}
$counts = array_count_values(array_column(staff_due_dates($cardId ?: null), 'status'));

$pageTitle = 'Card Due Dates';
$activeNav = 'staff_due_dates';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb(['Staff' => url('pages/staff/index.php'), 'Due dates' => null]) ?>

<section class="page-header">
    <div>
        <h2 class="page-header__title">Credit card due dates</h2>
        <p class="page-header__subtitle">Statement cutoff is when the bank closes the bill; the payment due date is when it must be paid. Amounts come from the bank's SOA once it is recorded.</p>
    </div>
    <div class="page-header__actions no-print">
        <button type="button" class="btn btn--outline" data-print><?= icon('printer') ?> <span>Print</span></button>
    </div>
</section>

<section class="stat-grid" aria-label="Due date summary">
    <?php foreach (['overdue' => ['alert', 'danger'], 'due_soon' => ['calendar', 'warning'], 'partially_paid' => ['wallet', 'info'], 'upcoming' => ['calendar', 'primary']] as $key => [$ic, $tone]): ?>
        <a class="stat-card" href="<?= e(url('pages/staff/due_dates.php', array_filter(['status' => $key, 'card' => $cardId]))) ?>">
            <div class="stat-card__icon stat-card__icon--<?= e($tone) ?>"><?= icon($ic) ?></div>
            <div><p class="stat-card__label"><?= e(DUE_STATUS_LABELS[$key]) ?></p><p class="stat-card__value"><?= e($counts[$key] ?? 0) ?></p></div>
        </a>
    <?php endforeach; ?>
</section>

<form method="get" action="<?= e(url('pages/staff/due_dates.php')) ?>" class="toolbar no-print" aria-label="Filter">
    <div class="form-group">
        <label for="f_card">Card</label>
        <select id="f_card" name="card"><option value="">All cards</option>
            <?php foreach (credit_card_options(null, false) as $id => $label): ?><option value="<?= e($id) ?>" <?= $id === $cardId ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
        </select>
    </div>
    <div class="form-group">
        <label for="f_status">Status</label>
        <select id="f_status" name="status"><option value="">All statuses</option>
            <?php foreach (DUE_STATUS_LABELS as $k => $label): ?><option value="<?= e($k) ?>" <?= $k === $status ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
        </select>
    </div>
    <button type="submit" class="btn btn--outline"><?= icon('search') ?> <span>Apply</span></button>
</form>

<section class="panel panel--flush" aria-label="Due dates">
    <?php if (!$rows): ?>
        <p class="empty-inline">Nothing to show. Add a card's statement cutoff day, or record its SOA, to see its due dates.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table--cards">
                <thead><tr><th scope="col">Card</th><th scope="col">Statement cutoff</th><th scope="col" class="num">Statement balance</th><th scope="col" class="num">Minimum due</th>
                    <th scope="col" class="num">Paid</th><th scope="col" class="num">Total amount due</th><th scope="col">Payment due</th><th scope="col" class="num">Days left</th><th scope="col">Status</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr class="<?= $r['status'] === 'overdue' ? 'is-overdue' : '' ?>">
                        <td data-label="Card"><strong><?= e($r['card']['card_nickname']) ?></strong><br><span class="text-muted"><?= e($r['card']['issuer']) ?> •••• <?= e($r['card']['last_four']) ?></span></td>
                        <td data-label="Statement cutoff"><?= $r['cutoff'] ? e(format_date($r['cutoff'])) : '—' ?><?= $r['source'] === 'open' ? '<br><span class="text-muted">current period</span>' : '' ?></td>
                        <td data-label="Statement balance" class="num"><?= $r['balance'] !== null ? e(format_money($r['balance'])) : '<span class="text-muted">SOA not recorded</span>' ?></td>
                        <td data-label="Minimum due" class="num"><?= $r['minimum'] !== null ? e(format_money($r['minimum'])) : '—' ?></td>
                        <td data-label="Paid" class="num"><?= $r['source'] === 'soa' ? e(format_money($r['paid'])) : '—' ?></td>
                        <td data-label="Total amount due" class="num"><strong><?= $r['remaining'] !== null ? e(format_money($r['remaining'])) : '—' ?></strong></td>
                        <td data-label="Payment due"><?= $r['due'] ? e(format_date($r['due'])) : '<span class="text-muted">No due-date rule</span>' ?></td>
                        <td data-label="Days left" class="num<?= $r['days'] !== null && $r['days'] < 0 ? ' text-danger' : '' ?>"><?= $r['days'] === null ? '—' : ($r['days'] < 0 ? e(-$r['days']) . ' late' : e($r['days'])) ?></td>
                        <td data-label="Status"><?= staff_due_badge($r['status']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php require APP_ROOT . '/includes/footer.php'; ?>
