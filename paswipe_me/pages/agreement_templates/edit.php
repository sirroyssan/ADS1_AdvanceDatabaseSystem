<?php
/**
 * Agreement Templates - edit. Agreements already generated keep their own text.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/template_functions.php';

$currentUser = require_admin();

$template = find_or_redirect(template_find(request_id()), 'pages/agreement_templates/index.php', 'Template');
$fields = template_fields();
$data = form_data_from_row($fields, $template);
$errors = [];

if (is_post()) {
    require_csrf('pages/agreement_templates/edit.php?id=' . $template['id']);
    [$data, $errors] = template_validate($fields, $_POST, $template['id']);

    if (!$errors) {
        try {
            db_update('agreement_templates', $data, $template['id']);
            log_activity('update', 'agreement_template', $template['id'], 'Updated agreement template "' . $data['name'] . '"');
            flash('success', 'Template "' . $data['name'] . '" was updated successfully.');
            redirect('pages/agreement_templates/view.php', ['id' => $template['id']]);
        } catch (PDOException $e) {
            $errors['_form'] = db_error_message($e);
        }
    }
}

$pageTitle = 'Edit Agreement Template';
$activeNav = 'agreement_templates';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb([
    'Agreement Templates' => url('pages/agreement_templates/index.php'),
    $template['name'] => url('pages/agreement_templates/view.php', ['id' => $template['id']]),
    'Edit' => null,
]) ?>

<section class="page-header">
    <div>
        <h2 class="page-header__title">Edit agreement template</h2>
        <p class="page-header__subtitle">Tip: for a significant wording change, save it as a new version instead.</p>
    </div>
</section>

<?= render_record_form(url('pages/agreement_templates/edit.php', ['id' => $template['id']]), $fields, $data, $errors, 'Save changes',
    url('pages/agreement_templates/view.php', ['id' => $template['id']])) ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
