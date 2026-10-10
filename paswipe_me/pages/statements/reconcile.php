<?php
/**
 * Statements - enter the figures from the bank's Statement of Account (SOA)
 * and compare them with the statement calculated from the card's records.
 * Saved as a new statement_reconciliations row (history is kept); nothing in
 * the records is changed to make the totals match.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/statement_functions.php';

$currentUser = require_admin();

$found = find_or_redirect(statement_find(request_id()), 'pages/statements/index.php', 'Statement');
statement_sync_card($found['credit_card_id']);
$statement = statement_find($found['id']);
$id = $statement['id'];

$fields = [
    'bank_statement_balance' => ['label' => 'Bank SOA statement balance (total amount due)', 'type' => 'decimal', 'required' => true,
                                 'help' => 'The statement balance printed on the SOA — not your current outstanding balance. Negative for a credit balance.'],
    'bank_minimum_due'       => ['label' => 'Bank minimum amount due', 'type' => 'decimal', 'min' => 0],
    'bank_previous_balance'  => ['label' => 'Bank previous balance', 'type' => 'decimal', 'help' => 'Optional.'],
    'bank_payments_credits'  => ['label' => 'Bank payments and credits', 'type' => 'decimal', 'min' => 0, 'help' => 'Optional: total payments and credits on the SOA.'],
    'notes'                  => ['label' => 'Reconciliation notes', 'type' => 'textarea', 'rows' => 2],
];
$last = db_row('SELECT * FROM statement_reconciliations WHERE statement_id = :s ORDER BY created_at DESC, id DESC LIMIT 1', [':s' => $id]);
$data = form_defaults($fields, $last ? array_intersect_key($last, $fields) : []);
$errors = [];

if (is_post()) {
    require_csrf('pages/statements/reconcile.php?id=' . $id);
    [$data, $errors] = validate_fields($fields, $_POST);

    if (!$errors) {
        try {
            $row = db_transaction(static fn () => statement_reconcile($statement, $data, $currentUser['id']));
            if ($row['status'] === 'balanced') {
                flash('success', 'Balanced: the bank SOA statement balance ' . format_money($row['bank_statement_balance'])
                    . ' matches the system-calculated statement balance.');
            } else {
                flash('warning', 'Difference detected — review transactions and fees. Bank SOA statement balance: ' . format_money($row['bank_statement_balance'])
                    . ' · System-calculated statement balance: ' . format_money($row['system_statement_balance'])
                    . ' · Difference: ' . format_money($row['difference']) . '.');
            }
            foreach (reconciliation_comparison($row, $statement) as [$label, $bank, $system, $diff]) {
                if ($label !== 'Statement balance (total amount due)' && $diff !== null && abs($diff) >= 0.005) {
                    flash('warning', $label . ' differs: bank ' . format_money($bank) . ', system ' . format_money($system) . '.');
                }
            }
            redirect('pages/statements/view.php', ['id' => $id]);
        } catch (PDOException $e) {
            $errors['_form'] = db_error_message($e);
        }
    }
}

$pageTitle = 'Reconcile Statement';
$activeNav = 'statements';
require APP_ROOT . '/includes/header.php';

$summary = '<div class="recon-system">'
    . '<p class="recon-system__title">Compared with the statement calculated from your records</p>'
    . render_details([
        'Credit card'       => e($statement['card_nickname'] . ' •••• ' . $statement['last_four']),
        'Statement cutoff'  => e(format_date($statement['cutoff_date'] ?: $statement['statement_date']))
            . ($statement['period_start_date'] ? ' <span class="text-muted">(period from ' . e(format_date($statement['period_start_date'])) . ')</span>' : ''),
        'System statement balance' => '<strong>' . e(format_money($statement['total_amount_due'])) . '</strong>',
        'System minimum due' => $statement['source'] === 'generated' && $statement['minimum_due_status'] === 'unconfirmed'
            ? '<span class="status status--warning">Needs confirmation</span>' : e(format_money($statement['minimum_amount_due'])),
        'System previous balance' => e(format_money($statement['previous_balance'])),
        'System payments and credits' => e(format_money((float) $statement['payments'] + (float) $statement['credits'])),
    ])
    . '</div>';
?>

<?= render_breadcrumb([
    'Statements' => url('pages/statements/index.php'),
    statement_label($statement) => url('pages/statements/view.php', ['id' => $id]),
    'Reconcile' => null,
]) ?>

<section class="page-header">
    <div>
        <h2 class="page-header__title">Enter the bank's SOA</h2>
        <p class="page-header__subtitle">Type the figures from the Statement of Account the bank sent. They are compared with your records; nothing is changed automatically.</p>
    </div>
</section>

<?= render_record_form(url('pages/statements/reconcile.php', ['id' => $id]), $fields, $data, $errors, 'Compare and save',
    url('pages/statements/view.php', ['id' => $id]), $summary, 'reconcile-form') ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
