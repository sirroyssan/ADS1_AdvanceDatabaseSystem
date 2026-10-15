<?php
/**
 * Demand Letter Templates - edit. Letters already generated keep their own content.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/template_functions.php';

$currentUser = require_admin();

$template = find_or_redirect(template_find(request_id()), 'pages/letter_templates/index.php', 'Template');
$fields = template_fields();
$data = form_data_from_row($fields, $template);
$errors = [];

if (is_post()) {
    require_csrf('pages/letter_templates/edit.php?id=' . $template['id']);
    [$data, $errors] = template_validate($fields, $_POST, $template['id']);

    if (!$errors) {
        try {
            db_update('demand_letter_templates', $data, $template['id']);
            log_activity('update', 'demand_letter_template', $template['id'], 'Updated demand letter template "' . $data['name'] . '"');
            flash('success', 'Template "' . $data['name'] . '" was updated successfully.');
            redirect('pages/letter_templates/view.php', ['id' => $template['id']]);
        } catch (PDOException $e) {
            $errors['_form'] = db_error_message($e);
        }
    }
}

$pageTitle = 'Edit Demand Letter Template';
$activeNav = 'letter_templates';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb([
    'Demand Letter Templates' => url('pages/letter_templates/index.php'),
    $template['name'] => url('pages/letter_templates/view.php', ['id' => $template['id']]),
    'Edit' => null,
]) ?>

<section class="page-header">
    <div>
        <h2 class="page-header__title">Edit demand letter template</h2>
        <p class="page-header__subtitle">Tip: for a significant wording change, save it as a new version instead.</p>
    </div>
</section>

<?= render_record_form(url('pages/letter_templates/edit.php', ['id' => $template['id']]), $fields, $data, $errors, 'Save changes',
    url('pages/letter_templates/view.php', ['id' => $template['id']])) ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
