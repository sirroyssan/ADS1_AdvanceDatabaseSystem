<?php
/**
 * Penalty Rules - edit. Changing a rule does not change penalties already applied
 * (applied_penalties stores its own computed amount).
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/penalty_rule_functions.php';

$currentUser = require_admin();

$rule = find_or_redirect(penalty_rule_find(request_id()), 'pages/penalty_rules/index.php', 'Penalty rule');
$fields = penalty_rule_fields($rule);
$data = form_data_from_row($fields, $rule);
$errors = [];

if (is_post()) {
    require_csrf('pages/penalty_rules/edit.php?id=' . $rule['id']);
    [$data, $errors] = penalty_rule_validate($fields, $_POST, $rule['id']);

    if (!$errors) {
        try {
            db_update('penalty_rules', $data, $rule['id']);
            log_activity('update', 'penalty_rule', $rule['id'], 'Updated penalty rule "' . $data['name'] . '"', array_intersect_key($rule, $data), $data);
            flash('success', 'Penalty rule "' . $data['name'] . '" was updated successfully.');
            redirect('pages/penalty_rules/view.php', ['id' => $rule['id']]);
        } catch (PDOException $e) {
            $errors['_form'] = db_error_message($e);
        }
    }
}

$pageTitle = 'Edit Penalty Rule';
$activeNav = 'penalty_rules';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb([
    'Penalty Rules' => url('pages/penalty_rules/index.php'),
    $rule['name'] => url('pages/penalty_rules/view.php', ['id' => $rule['id']]),
    'Edit' => null,
]) ?>

<section class="page-header">
    <div>
        <h2 class="page-header__title">Edit penalty rule</h2>
        <p class="page-header__subtitle">Penalties already applied keep their recorded amounts.</p>
    </div>
</section>

<?= render_record_form(url('pages/penalty_rules/edit.php', ['id' => $rule['id']]), $fields, $data, $errors, 'Save changes',
    url('pages/penalty_rules/view.php', ['id' => $rule['id']])) ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
