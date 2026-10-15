<?php
/**
 * Merchants - edit.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/merchant_functions.php';

$currentUser = require_admin();

$merchant = find_or_redirect(merchant_find(request_id()), 'pages/merchants/index.php', 'Merchant');
$fields = merchant_fields($merchant['category_id']);
$data = form_data_from_row($fields, $merchant);
$errors = [];

if (is_post()) {
    require_csrf('pages/merchants/edit.php?id=' . $merchant['id']);
    [$data, $errors] = validate_fields($fields, $_POST, $merchant['id']);

    if (!$errors) {
        try {
            db_update('merchants', $data, $merchant['id']);
            log_activity('update', 'merchant', $merchant['id'], 'Updated merchant "' . $data['merchant_name'] . '"',
                array_intersect_key($merchant, $data), $data);
            flash('success', 'Merchant "' . $data['merchant_name'] . '" was updated successfully.');
            redirect('pages/merchants/view.php', ['id' => $merchant['id']]);
        } catch (PDOException $e) {
            $errors['_form'] = db_error_message($e);
        }
    }
}

$pageTitle = 'Edit Merchant';
$activeNav = 'merchants';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb([
    'Merchants' => url('pages/merchants/index.php'),
    merchant_label($merchant) => url('pages/merchants/view.php', ['id' => $merchant['id']]),
    'Edit' => null,
]) ?>

<section class="page-header">
    <div>
        <h2 class="page-header__title">Edit merchant</h2>
        <p class="page-header__subtitle">Last updated <?= e(format_datetime($merchant['updated_at'])) ?></p>
    </div>
</section>

<?= render_record_form(url('pages/merchants/edit.php', ['id' => $merchant['id']]), $fields, $data, $errors, 'Save changes',
    url('pages/merchants/view.php', ['id' => $merchant['id']])) ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
