<?php
/**
 * Installment Rules - edit. Plans already created keep the terms they were
 * created with (installment_plans stores its own terms and a snapshot).
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/rule_functions.php';

$currentUser = require_admin();

$rule = find_or_redirect(installment_rule_find(request_id()), 'pages/installment_rules/index.php', 'Installment rule');
$fields = installment_rule_fields($rule);
$data = form_data_from_row($fields, ['issuer' => bank_canonical($rule['issuer'])] + $rule);
$errors = [];

if (is_post()) {
    require_csrf('pages/installment_rules/edit.php?id=' . $rule['id']);
    [$data, $errors] = installment_rule_validate($fields, $_POST, $rule['id']);

    if (!$errors) {
        try {
            db_update('installment_rules', $data, $rule['id']);
            log_activity('update', 'installment_rule', $rule['id'], 'Updated installment rule "' . $data['name'] . '"', array_intersect_key($rule, $data), $data);
            flash('success', 'Installment rule "' . $data['name'] . '" was updated successfully.');
            redirect('pages/installment_rules/view.php', ['id' => $rule['id']]);
        } catch (PDOException $e) {
            $errors['_form'] = db_error_message($e);
        }
    }
}

$pageTitle = 'Edit Installment Rule';
$activeNav = 'installment_rules';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb([
    'Installment Rules' => url('pages/installment_rules/index.php'),
    $rule['name'] => url('pages/installment_rules/view.php', ['id' => $rule['id']]),
    'Edit' => null,
]) ?>

<section class="page-header">
    <div>
        <h2 class="page-header__title">Edit installment rule</h2>
        <p class="page-header__subtitle">Installment plans already created keep their own terms.</p>
    </div>
</section>

<?= render_record_form(url('pages/installment_rules/edit.php', ['id' => $rule['id']]), $fields, $data, $errors, 'Save changes',
    url('pages/installment_rules/view.php', ['id' => $rule['id']])) ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
