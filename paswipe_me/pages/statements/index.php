<?php
/**
 * Statements - list, search, filter by card and date range, print with totals.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/statement_functions.php';

$currentUser = require_admin();
// Statements are generated at each card's cutoff from its records (includes/statement_engine.php).
statement_sync_all();

$state = list_state([
    'path'    => 'pages/statements/index.php',
    'title'   => 'Credit Card Statements',
    'search'  => 'Search statement number, card or issuer…',
    'filters' => [
        'card' => ['label' => 'Card', 'type' => 'select', 'options' => credit_card_options(null, false), 'all' => 'All cards'],
        'from' => ['label' => 'From', 'type' => 'date'],
        'to'   => ['label' => 'To', 'type' => 'date'],
        'recon' => ['label' => 'Reconciliation', 'type' => 'select', 'options' => ['pending' => 'Pending review', 'balanced' => 'Balanced', 'difference' => 'Difference detected'], 'all' => 'Any reconciliation'],
    ],
    'sorts'   => [
        'newest' => ['Newest first', 's.statement_date DESC'],
        'oldest' => ['Oldest first', 's.statement_date ASC'],
        'due'    => ['Due date', 's.due_date ASC'],
        'amount' => ['Highest amount due', 's.total_amount_due DESC'],
    ],
]);

$where = [];
$params = [];
add_search_condition($state, ['s.statement_number', 'c.card_nickname', 'c.issuer', 'c.last_four'], $where, $params);
if ($state['filters']['card'] !== '') {
    $where[] = 's.credit_card_id = :card';
    $params[':card'] = $state['filters']['card'];
}
if ($state['filters']['from'] !== '') {
    $where[] = 's.statement_date >= :from';
    $params[':from'] = $state['filters']['from'];
}
if ($state['filters']['to'] !== '') {
    $where[] = 's.statement_date <= :to';
    $params[':to'] = $state['filters']['to'];
}

if ($state['filters']['recon'] !== '') {
    $where[] = 'COALESCE(' . statement_reconciliation_sql() . ", 'pending') = :recon";
    $params[':recon'] = $state['filters']['recon'];
}

$from = 'credit_card_statements s JOIN credit_cards c ON c.id = s.credit_card_id';
$result = run_list(
    $state,
    's.id, s.statement_number, s.statement_date, s.due_date, s.total_amount_due, s.minimum_amount_due, s.attachment_document_id,
     s.period_start_date, s.cutoff_date, s.source, s.minimum_due_status, s.due_date_status,
     COALESCE(' . statement_reconciliation_sql() . ", 'pending') AS recon,
     c.card_nickname, c.last_four,
     (SELECT COALESCE(SUM(p.amount), 0) FROM credit_card_payments p WHERE p.statement_id = s.id) AS paid",
    $from,
    $where,
    $params
);
$totals = db_row('SELECT COALESCE(SUM(s.total_amount_due), 0) AS due, COALESCE(SUM(s.minimum_amount_due), 0) AS minimum FROM ' . $from
    . ($where ? ' WHERE ' . implode(' AND ', $where) : ''), $params);

$pageTitle = 'Statements';
$activeNav = 'statements';
require APP_ROOT . '/includes/header.php';
?>

<?= render_print_header($state, $result['total'], $result['pager']) ?>
<?= render_list_header($state, 'Generated automatically at each card\'s cutoff from its records. Enter the bank\'s SOA on a statement to reconcile it.', url('pages/statements/create.php'), 'Add manual statement') ?>
<?= render_list_toolbar($state) ?>

<section class="panel panel--flush" aria-label="Statement list">
    <?php if ($result['total'] === 0): ?>
        <?= render_empty_state($state, null, 'Set a statement cutoff day on a credit card: its statements are then generated automatically at every cutoff.', 'calendar') ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table--cards">
                <thead>
                <tr>
                    <th scope="col" class="col-num">#</th>
                    <th scope="col">Card</th>
                    <th scope="col">Period / cutoff</th>
                    <th scope="col">Due date</th>
                    <th scope="col" class="num">Statement balance</th>
                    <th scope="col" class="num">Minimum</th>
                    <th scope="col">Reconciliation</th>
                    <th scope="col" class="actions no-print"><span class="visually-hidden">Actions</span></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($result['rows'] as $i => $row): ?>
                    <tr>
                        <td class="col-num" data-label="#"><?= e($result['pager']['offset'] + $i + 1) ?></td>
                        <td data-label="Card"><?= e($row['card_nickname']) ?> <span class="text-muted">•••• <?= e($row['last_four']) ?></span></td>
                        <td data-label="Period / cutoff"><a class="table-link" href="<?= e(url('pages/statements/view.php', ['id' => $row['id']])) ?>"><?= $row['period_start_date']
                                ? e(format_date($row['period_start_date']) . ' – ' . format_date($row['cutoff_date'])) : e(format_date($row['cutoff_date'] ?: $row['statement_date'])) ?></a>
                            <br><span class="text-muted"><?= $row['source'] === 'generated' ? 'Generated' : 'Entered by hand' ?><?= $row['statement_number'] ? ' · #' . e($row['statement_number']) : '' ?></span></td>
                        <td data-label="Due date"><?= e(format_date($row['due_date'])) ?><?= $row['source'] === 'generated' && $row['due_date_status'] === 'unconfirmed' ? '<br><span class="status status--warning">Needs confirmation</span>' : '' ?></td>
                        <td data-label="Statement balance" class="num"><?= e(format_money($row['total_amount_due'])) ?></td>
                        <td data-label="Minimum" class="num"><?= $row['source'] === 'generated' && $row['minimum_due_status'] === 'unconfirmed' ? '<span class="status status--warning">Needs confirmation</span>' : e(format_money($row['minimum_amount_due'])) ?></td>
                        <td data-label="Reconciliation"><?= reconciliation_badge($row['recon']) ?></td>
                        <td class="actions no-print">
                            <div class="row-actions">
                                <?= icon_link(url('pages/statements/view.php', ['id' => $row['id']]), 'eye', 'View statement') ?>
                                <a data-modal-form href="<?= e(url('pages/statements/reconcile.php', ['id' => $row['id']])) ?>" class="icon-button" title="Enter bank SOA" aria-label="Enter bank SOA"><?= icon('scale') ?></a>
                                <?= icon_link(url('pages/statements/edit.php', ['id' => $row['id']]), 'edit', 'Edit statement') ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                <tr>
                    <td colspan="4">Total (all <?= e(format_number($result['total'])) ?> matching)</td>
                    <td class="num"><?= e(format_money($totals['due'])) ?></td>
                    <td class="num"><?= e(format_money($totals['minimum'])) ?></td>
                    <td colspan="2"></td>
                </tr>
                </tfoot>
            </table>
        </div>
        <?= render_list_footer($state, $result) ?>
    <?php endif; ?>
</section>

<?php require APP_ROOT . '/includes/footer.php'; ?>
