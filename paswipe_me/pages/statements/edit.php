<?php
/**
 * Statements - correct a recorded SOA (amount, minimum, due date, cutoff, notes).
 * The card cannot change; every change is kept in the activity log.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/statement_functions.php';

$currentUser = require_admin();

$statement = find_or_redirect(statement_find(request_id()), 'pages/statements/index.php', 'Statement');
$card = db_row('SELECT * FROM credit_cards WHERE id = :c', [':c' => $statement['credit_card_id']]);
$fields = statement_fields($statement, true);
$data = form_data_from_row($fields, $statement);
if (!$data['cutoff_date']) {
    $data['cutoff_date'] = $statement['statement_date'];   // older statements had no cutoff date
}
$errors = [];

if (is_post()) {
    require_csrf('pages/statements/edit.php?id=' . $statement['id']);
    [$data, $errors] = statement_validate($fields, $_POST, $statement['id'], $statement['credit_card_id']);

    if (!$errors) {
        try {
            db_update('credit_card_statements', $data + ['updated_at' => date('Y-m-d H:i:s')], $statement['id']);
            log_activity('update', 'statement', $statement['id'], 'Updated SOA cut off ' . $data['cutoff_date'], array_intersect_key($statement, $data), $data);
            flash('success', 'SOA was updated.');
            redirect('pages/statements/view.php', ['id' => $statement['id']]);
        } catch (PDOException $e) {
            $errors['_form'] = str_contains($e->getMessage(), 'Duplicate')
                ? 'An SOA is already recorded for this card and period.' : db_error_message($e);
        }
    }
}

$pageTitle = 'Edit SOA';
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
        <h2 class="page-header__title">Edit SOA</h2>
        <p class="page-header__subtitle"><?= e(statement_label($statement)) ?> · as printed on the bank's Statement of Account</p>
    </div>
</section>

<?= render_record_form(url('pages/statements/edit.php', ['id' => $statement['id']]), $fields, $data, $errors, 'Save changes',
    url('pages/statements/view.php', ['id' => $statement['id']]),
    statement_card_context($card, statement_period_of($statement, $card))) ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
