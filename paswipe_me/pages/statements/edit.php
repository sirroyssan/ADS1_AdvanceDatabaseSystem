<?php
/**
 * Statements - edit.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/statement_functions.php';

$currentUser = require_admin();

$statement = find_or_redirect(statement_find(request_id()), 'pages/statements/index.php', 'Statement');
$fields = statement_fields($statement);
$data = form_data_from_row($fields, $statement);
$errors = [];

if (is_post()) {
    require_csrf('pages/statements/edit.php?id=' . $statement['id']);
    [$data, $errors] = statement_validate($fields, $_POST, $statement['id']);

    if (!$errors) {
        try {
            db_update('credit_card_statements', $data, $statement['id']);
            log_activity('update', 'statement', $statement['id'], 'Updated statement dated ' . $data['statement_date'], array_intersect_key($statement, $data), $data);
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
        <p class="page-header__subtitle"><?= e(statement_label($statement)) ?></p>
    </div>
</section>

<?= render_record_form(url('pages/statements/edit.php', ['id' => $statement['id']]), $fields, $data, $errors, 'Save changes',
    url('pages/statements/view.php', ['id' => $statement['id']])) ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
