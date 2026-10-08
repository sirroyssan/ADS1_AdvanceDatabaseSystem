<?php
/**
 * Statements - create.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/statement_functions.php';

$currentUser = require_admin();

$fields = statement_fields();
$preset = input_string($_GET, 'credit_card_id');
$data = form_defaults($fields, is_valid_uuid($preset) ? ['credit_card_id' => $preset] : []);
$errors = [];

if (is_post()) {
    require_csrf('pages/statements/create.php');
    [$data, $errors] = statement_validate($fields, $_POST);

    if (!$errors) {
        try {
            $id = new_uuid();
            db_insert('credit_card_statements', ['id' => $id] + $data);
            log_activity('create', 'statement', $id, 'Recorded statement dated ' . $data['statement_date'], null, $data);
            flash('success', 'Statement was added successfully.');
            redirect('pages/statements/view.php', ['id' => $id]);
        } catch (PDOException $e) {
            $errors['_form'] = db_error_message($e);
        }
    }
}

$pageTitle = 'Add Statement';
$activeNav = 'statements';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb(['Statements' => url('pages/statements/index.php'), 'Add' => null]) ?>

<section class="page-header">
    <div>
        <h2 class="page-header__title">Add statement</h2>
        <p class="page-header__subtitle">Copy the amounts from the bank's monthly statement.</p>
    </div>
</section>

<?= render_record_form(url('pages/statements/create.php'), $fields, $data, $errors, 'Save statement', url('pages/statements/index.php')) ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
