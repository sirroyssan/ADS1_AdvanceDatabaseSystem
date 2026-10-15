<?php
/**
 * Payment Methods - create.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/payment_method_functions.php';

$currentUser = require_admin();

$fields = payment_method_fields();
$preset = input_string($_GET, 'borrower_id');
$data = form_defaults($fields, is_valid_uuid($preset) ? ['borrower_id' => $preset] : []);
$errors = [];

if (is_post()) {
    require_csrf('pages/payment_methods/create.php');
    [$data, $errors] = payment_method_validate($fields, $_POST);

    if (!$errors) {
        try {
            $id = new_uuid();
            db_insert('payment_methods', ['id' => $id] + $data);
            log_activity('create', 'payment_method', $id, 'Created payment method "' . $data['display_name'] . '"', null, $data);
            flash('success', 'Payment method "' . $data['display_name'] . '" was added successfully.');
            redirect('pages/payment_methods/view.php', ['id' => $id]);
        } catch (PDOException $e) {
            $errors['_form'] = db_error_message($e);
        }
    }
}

$pageTitle = 'Add Payment Method';
$activeNav = 'payment_methods';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb(['Payment Methods' => url('pages/payment_methods/index.php'), 'Add' => null]) ?>

<section class="page-header">
    <div>
        <h2 class="page-header__title">Add payment method</h2>
        <p class="page-header__subtitle">Only masked account details are stored, never full account numbers or PINs.</p>
    </div>
</section>

<?= render_record_form(url('pages/payment_methods/create.php'), $fields, $data, $errors, 'Save payment method', url('pages/payment_methods/index.php')) ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
