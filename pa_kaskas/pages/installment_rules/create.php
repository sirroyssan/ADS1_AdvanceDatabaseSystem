<?php
/**
 * Installment Rules - create.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/rule_functions.php';

$currentUser = require_admin();

$fields = installment_rule_fields();
$data = form_defaults($fields);
$errors = [];

if (is_post()) {
    require_csrf('pages/installment_rules/create.php');
    [$data, $errors] = installment_rule_validate($fields, $_POST);

    if (!$errors) {
        try {
            $id = new_uuid();
            db_insert('installment_rules', ['id' => $id] + $data + ['owner_user_id' => owner_id()]);
            log_activity('create', 'installment_rule', $id, 'Created installment rule "' . $data['name'] . '"', null, $data);
            flash('success', 'Installment rule "' . $data['name'] . '" was added successfully.');
            redirect('pages/installment_rules/view.php', ['id' => $id]);
        } catch (PDOException $e) {
            $errors['_form'] = db_error_message($e);
        }
    }
}

$pageTitle = 'Add Installment Rule';
$activeNav = 'installment_rules';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb(['Installment Rules' => url('pages/installment_rules/index.php'), 'Add' => null]) ?>

<section class="page-header">
    <div>
        <h2 class="page-header__title">Add installment rule</h2>
        <p class="page-header__subtitle">Enter the terms exactly as your bank states them. They are offered when you record an installment purchase.</p>
    </div>
</section>

<?= render_record_form(url('pages/installment_rules/create.php'), $fields, $data, $errors, 'Save rule', url('pages/installment_rules/index.php')) ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
