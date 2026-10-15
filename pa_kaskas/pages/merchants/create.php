<?php
/**
 * Merchants - create.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/merchant_functions.php';

$currentUser = require_admin();

$fields = merchant_fields();
$data = form_defaults($fields);
$errors = [];

if (is_post()) {
    require_csrf('pages/merchants/create.php');
    [$data, $errors] = validate_fields($fields, $_POST);

    if (!$errors) {
        try {
            $id = new_uuid();
            db_insert('merchants', ['id' => $id] + $data);
            log_activity('create', 'merchant', $id, 'Created merchant "' . $data['merchant_name'] . '"', null, $data);
            flash('success', 'Merchant "' . $data['merchant_name'] . '" was added successfully.');
            redirect('pages/merchants/view.php', ['id' => $id]);
        } catch (PDOException $e) {
            $errors['_form'] = db_error_message($e);
        }
    }
}

$pageTitle = 'Add Merchant';
$activeNav = 'merchants';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb(['Merchants' => url('pages/merchants/index.php'), 'Add' => null]) ?>

<section class="page-header">
    <div>
        <h2 class="page-header__title">Add merchant</h2>
        <p class="page-header__subtitle">Record a store or online shop where card purchases are made.</p>
    </div>
</section>

<?= render_record_form(url('pages/merchants/create.php'), $fields, $data, $errors, 'Save merchant', url('pages/merchants/index.php')) ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
