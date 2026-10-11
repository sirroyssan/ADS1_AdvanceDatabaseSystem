<?php
/**
 * Penalty Rules - create.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/penalty_rule_functions.php';

$currentUser = require_admin();

$fields = penalty_rule_fields();
$data = form_defaults($fields);
$errors = [];

if (is_post()) {
    require_csrf('pages/penalty_rules/create.php');
    [$data, $errors] = penalty_rule_validate($fields, $_POST);

    if (!$errors) {
        try {
            $id = new_uuid();
            db_insert('penalty_rules', ['id' => $id] + $data + ['owner_user_id' => owner_id()]);
            log_activity('create', 'penalty_rule', $id, 'Created penalty rule "' . $data['name'] . '"', null, $data);
            flash('success', 'Penalty rule "' . $data['name'] . '" was added successfully.');
            redirect('pages/penalty_rules/view.php', ['id' => $id]);
        } catch (PDOException $e) {
            $errors['_form'] = db_error_message($e);
        }
    }
}

$pageTitle = 'Add Penalty Rule';
$activeNav = 'penalty_rules';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb(['Penalty Rules' => url('pages/penalty_rules/index.php'), 'Add' => null]) ?>

<section class="page-header">
    <div>
        <h2 class="page-header__title">Add penalty rule</h2>
        <p class="page-header__subtitle">Percentage rules use the rate; fixed rules use the fixed amount. The maximum charge caps either one.</p>
    </div>
</section>

<?= render_record_form(url('pages/penalty_rules/create.php'), $fields, $data, $errors, 'Save rule', url('pages/penalty_rules/index.php')) ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
