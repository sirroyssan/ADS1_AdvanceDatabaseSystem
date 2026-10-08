<?php
/**
 * Cash Advances - create.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/cash_advance_functions.php';

$currentUser = require_admin();

$fields = cash_advance_fields();
$preset = input_string($_GET, 'credit_card_id');
$data = form_defaults($fields, is_valid_uuid($preset) ? ['credit_card_id' => $preset] : []);
$errors = [];

if (is_post()) {
    require_csrf('pages/cash_advances/create.php');
    [$data, $errors] = cash_advance_validate($fields, $_POST);

    if (!$errors) {
        try {
            $id = new_uuid();
            db_insert('cash_advances', ['id' => $id] + $data);
            log_activity('create', 'cash_advance', $id, 'Recorded cash advance of ' . format_money($data['amount']), null, $data);
            flash('success', 'Cash advance was recorded.');
            redirect('pages/cash_advances/view.php', ['id' => $id]);
        } catch (PDOException $e) {
            $errors['_form'] = db_error_message($e);
        }
    }
}

$pageTitle = 'Add Cash Advance';
$activeNav = 'cash_advances';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb(['Cash Advances' => url('pages/cash_advances/index.php'), 'Add' => null]) ?>

<section class="page-header">
    <div>
        <h2 class="page-header__title">Add cash advance</h2>
        <p class="page-header__subtitle">The total fills in automatically from amount, fee and interest.</p>
    </div>
</section>

<?= render_record_form(url('pages/cash_advances/create.php'), $fields, $data, $errors, 'Save cash advance', url('pages/cash_advances/index.php')) ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
