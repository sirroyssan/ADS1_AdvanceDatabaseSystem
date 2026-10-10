<?php
/**
 * Statements - edit.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/statement_functions.php';

$currentUser = require_admin();

$statement = find_or_redirect(statement_find(request_id()), 'pages/statements/index.php', 'Statement');
$generated = $statement['source'] === 'generated';
// Generated statements: the figures come from the records; only dates, the minimum due and an adjustment are edited here.
$fields = $generated ? generated_statement_fields($statement) : statement_fields($statement);
$row = $statement;
if ($generated && $statement['minimum_due_status'] !== 'manual') {
    $row['minimum_amount_due'] = null;   // blank = by the card's rule
}
$data = form_data_from_row($fields, $row);
$errors = [];

if (is_post()) {
    require_csrf('pages/statements/edit.php?id=' . $statement['id']);
    [$data, $errors] = $generated ? generated_statement_validate($fields, $_POST, $statement) : statement_validate($fields, $_POST, $statement['id']);

    if (!$errors) {
        try {
            $save = $data;
            if ($generated) {
                if ($data['due_date'] !== $statement['due_date']) {
                    $save['due_date_status'] = 'manual';
                }
                $save['minimum_due_status'] = $data['minimum_amount_due'] === null ? 'unconfirmed' : 'manual';
                $save['minimum_amount_due'] = $data['minimum_amount_due'] ?? '0.00';
                if (isset($data['cutoff_date']) && $data['cutoff_date'] !== $statement['cutoff_date']) {
                    if ($statement['statement_date'] === $statement['cutoff_date'] && $data['statement_date'] === $statement['statement_date']) {
                        $save['statement_date'] = $data['cutoff_date'];
                    }
                }
            }
            db_transaction(static function () use ($save, $statement, $generated) {
                db_update('credit_card_statements', $save + ['updated_at' => date('Y-m-d H:i:s')], $statement['id']);
                if ($generated && isset($save['cutoff_date']) && $save['cutoff_date'] !== $statement['cutoff_date']) {
                    // Keep the stored billing cycle in step with the statement's period.
                    db_exec('UPDATE card_billing_cycles SET cycle_end_date = :n, statement_date = :sd
                              WHERE credit_card_id = :c AND cycle_start_date = :s AND cycle_end_date = :o',
                        [':n' => $save['cutoff_date'], ':sd' => $save['statement_date'], ':c' => $statement['credit_card_id'],
                         ':s' => $statement['period_start_date'], ':o' => $statement['cutoff_date']]);
                }
            });
            if ($generated) {
                statement_sync_card($statement['credit_card_id']);
            }
            log_activity('update', 'statement', $statement['id'], 'Updated statement dated ' . $data['statement_date']
                . ($generated && (string) $data['adjustments'] !== (string) $statement['adjustments'] ? ' · adjustment ' . format_money($data['adjustments']) . ': ' . $data['adjustment_reason'] : ''),
                array_intersect_key($statement, $data), $data);
            flash('success', 'Statement was updated successfully.');
            redirect('pages/statements/view.php', ['id' => $statement['id']]);
        } catch (PDOException $e) {
            $errors['_form'] = db_error_message($e);
        }
    }
}

$pageTitle = 'Edit Statement';
$activeNav = 'statements';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb([
    'Statements' => url('pages/statements/index.php'),
    statement_label($statement) => url('pages/statements/view.php', ['id' => $statement['id']]),
    'Edit' => null,
]) ?>

<section class="page-header">
    <div>
        <h2 class="page-header__title">Edit statement</h2>
        <p class="page-header__subtitle"><?= e(statement_label($statement)) ?><?= $generated
            ? ' · generated from the card\'s records: to correct a charge or payment, edit or add the record itself' : '' ?></p>
    </div>
</section>

<?= render_record_form(url('pages/statements/edit.php', ['id' => $statement['id']]), $fields, $data, $errors, 'Save changes',
    url('pages/statements/view.php', ['id' => $statement['id']])) ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
