<?php
/**
 * Payment Methods - edit.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/payment_method_functions.php';

$currentUser = require_admin();

$method = find_or_redirect(payment_method_find(request_id()), 'pages/payment_methods/index.php', 'Payment method');
$fields = payment_method_fields($method);
$data = form_data_from_row($fields, $method);
$errors = [];

if (is_post()) {
    require_csrf('pages/payment_methods/edit.php?id=' . $method['id']);
    [$data, $errors] = payment_method_validate($fields, $_POST, $method['id']);

    if (!$errors) {
        try {
            db_update('payment_methods', $data, $method['id']);
            log_activity('update', 'payment_method', $method['id'], 'Updated payment method "' . $data['display_name'] . '"',
                array_intersect_key($method, $data), $data);
            flash('success', 'Payment method "' . $data['display_name'] . '" was updated successfully.');
            redirect('pages/payment_methods/view.php', ['id' => $method['id']]);
        } catch (PDOException $e) {
            $errors['_form'] = db_error_message($e);
        }
    }
}

$pageTitle = 'Edit Payment Method';
$activeNav = 'payment_methods';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb([
    'Payment Methods' => url('pages/payment_methods/index.php'),
    $method['display_name'] => url('pages/payment_methods/view.php', ['id' => $method['id']]),
    'Edit' => null,
]) ?>

<section class="page-header">
    <div>
        <h2 class="page-header__title">Edit payment method</h2>
        <p class="page-header__subtitle">Last updated <?= e(format_datetime($method['updated_at'])) ?></p>
    </div>
</section>

<?= render_record_form(url('pages/payment_methods/edit.php', ['id' => $method['id']]), $fields, $data, $errors, 'Save changes',
    url('pages/payment_methods/view.php', ['id' => $method['id']])) ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
