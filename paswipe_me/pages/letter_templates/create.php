<?php
/**
 * Demand Letter Templates - create.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/template_functions.php';

$currentUser = require_admin();

$fields = template_fields();
$data = form_defaults($fields);
$errors = [];

if (is_post()) {
    require_csrf('pages/letter_templates/create.php');
    [$data, $errors] = template_validate($fields, $_POST);

    if (!$errors) {
        try {
            $id = new_uuid();
            db_insert('demand_letter_templates', ['id' => $id] + $data);
            log_activity('create', 'demand_letter_template', $id, 'Created demand letter template "' . $data['name'] . '"');
            flash('success', 'Template "' . $data['name'] . '" was added successfully.');
            redirect('pages/letter_templates/view.php', ['id' => $id]);
        } catch (PDOException $e) {
            $errors['_form'] = db_error_message($e);
        }
    }
}

$pageTitle = 'Add Demand Letter Template';
$activeNav = 'letter_templates';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb(['Demand Letter Templates' => url('pages/letter_templates/index.php'), 'Add' => null]) ?>

<section class="page-header">
    <div>
        <h2 class="page-header__title">Add demand letter template</h2>
        <p class="page-header__subtitle">Write the letter once; placeholders are replaced with the borrower's balance details later.</p>
    </div>
</section>

<?= render_record_form(url('pages/letter_templates/create.php'), $fields, $data, $errors, 'Save template', url('pages/letter_templates/index.php')) ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
