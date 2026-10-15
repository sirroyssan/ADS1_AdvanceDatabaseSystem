<?php
/**
 * Earnings - add or edit a saving goal (opened in a modal).
 */

require_once __DIR__ . '/../../includes/auth_check.php';

$currentUser = require_admin();
earnings_schema_upgrade();

$goal = null;
if (input_string($_GET, 'id') !== '') {
    $goal = find_or_redirect(savings_goal_find(request_id()), 'pages/earnings/index.php', 'Saving goal');
}
$fields = savings_goal_fields();
if ($goal) {
    $fields['status'] = ['label' => 'Status', 'type' => 'select', 'options' => SAVINGS_GOAL_STATUSES, 'required' => true];
}
$data = $goal ? form_data_from_row($fields, $goal) : form_defaults($fields);
$errors = [];
$self = url('pages/earnings/goal.php', $goal ? ['id' => $goal['id']] : []);

if (is_post()) {
    require_csrf('pages/earnings/index.php');
    [$data, $errors] = validate_fields($fields, $_POST, $goal['id'] ?? null);
    if (($data['target_date'] ?? null) && ($data['start_date'] ?? null) && $data['target_date'] < $data['start_date']) {
        $errors['target_date'] = '"Needed by" cannot be before "Count from".';
    }
    if (!$errors) {
        try {
            if ($goal) {
                $data['used_at'] = $data['status'] === 'used' ? ($goal['used_at'] ?: date('Y-m-d H:i:s')) : null;
                db_update('savings_goals', $data, $goal['id']);
                log_activity('update', 'savings_goal', $goal['id'], 'Updated saving goal ' . $data['name'], array_intersect_key($goal, $data), $data);
                flash('success', 'Saving goal "' . $data['name'] . '" was updated.');
            } else {
                $id = new_uuid();
                db_insert('savings_goals', ['id' => $id, 'owner_user_id' => owner_id(), 'status' => 'active', 'created_by' => $currentUser['id']] + $data);
                log_activity('create', 'savings_goal', $id, 'Added saving goal ' . $data['name'] . ' (' . format_money($data['target_amount']) . ')', null, $data);
                flash('success', 'Saving goal "' . $data['name'] . '" was added.');
            }
            redirect('pages/earnings/index.php');
        } catch (PDOException $e) {
            $errors['_form'] = db_error_message($e);
        }
    }
}

$pageTitle = $goal ? 'Edit saving goal' : 'Add saving goal';
$activeNav = 'earnings';
require APP_ROOT . '/includes/header.php';
?>

<section class="page-header">
    <div>
        <h2 class="page-header__title"><?= e($pageTitle) ?></h2>
        <p class="page-header__subtitle">The interest your borrowers pay counts toward it. It only keeps track; no money is moved.</p>
    </div>
</section>

<?= render_record_form($self, $fields, $data, $errors, $goal ? 'Save changes' : 'Add goal', url('pages/earnings/index.php')) ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
