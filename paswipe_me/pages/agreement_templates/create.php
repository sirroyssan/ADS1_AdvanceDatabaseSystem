<?php
/**
 * Agreement Templates - create.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/template_functions.php';

$currentUser = require_admin();

$fields = template_fields();
$data = form_defaults($fields);
$errors = [];

if (is_post()) {
    require_csrf('pages/agreement_templates/create.php');
    [$data, $errors] = template_validate($fields, $_POST);

    if (!$errors) {
        try {
            $id = new_uuid();
            db_insert('agreement_templates', ['id' => $id] + $data);
            log_activity('create', 'agreement_template', $id, 'Created agreement template "' . $data['name'] . '"');
            flash('success', 'Template "' . $data['name'] . '" was added successfully.');
            redirect('pages/agreement_templates/view.php', ['id' => $id]);
        } catch (PDOException $e) {
            $errors['_form'] = db_error_message($e);
        }
    }
}

$pageTitle = 'Add Agreement Template';
$activeNav = 'agreement_templates';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb(['Agreement Templates' => url('pages/agreement_templates/index.php'), 'Add' => null]) ?>

<section class="page-header">
    <div>
        <h2 class="page-header__title">Add agreement template</h2>
        <p class="page-header__subtitle">Write the agreement once; placeholders are replaced with the borrower's details later.</p>
    </div>
</section>

<?= render_record_form(url('pages/agreement_templates/create.php'), $fields, $data, $errors, 'Save template', url('pages/agreement_templates/index.php')) ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
