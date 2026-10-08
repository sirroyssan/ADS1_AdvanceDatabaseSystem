<?php
/**
 * Borrower Payments - record a payment received (starts as "submitted").
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/payment_functions.php';

$currentUser = require_admin();

$fields = payment_fields();
$preset = input_string($_GET, 'borrower_id');
$data = form_defaults($fields, is_valid_uuid($preset) ? ['borrower_id' => $preset] : []);
$errors = [];

if (is_post()) {
    require_csrf('pages/borrower_payments/create.php');
    [$data, $errors] = payment_validate($fields, $_POST);

    if (!$errors) {
        try {
            $id = new_uuid();
            $number = generate_reference('PAY', 'borrower_payments', 'payment_number');
            db_transaction(function () use ($id, $number, $data, $currentUser) {
                db_insert('borrower_payments', ['id' => $id, 'payment_number' => $number] + $data + ['status' => 'submitted', 'allocation_status' => 'unallocated']);
                payment_log(['id' => $id, 'status' => null], 'submitted', 'recorded', 'Recorded by administrator', $currentUser['id']);
            });
            log_activity('create', 'borrower_payment', $id, 'Recorded payment ' . $number . ' of ' . format_money($data['amount']), null, $data);
            flash('success', 'Payment ' . $number . ' was recorded. Review and approve it before allocating.');
            redirect('pages/borrower_payments/view.php', ['id' => $id]);
        } catch (PDOException $e) {
            $errors['_form'] = db_error_message($e);
        }
    }
}

$pageTitle = 'Record Borrower Payment';
$activeNav = 'borrower_payments';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb(['Borrower Payments' => url('pages/borrower_payments/index.php'), 'Record' => null]) ?>

<section class="page-header">
    <div>
        <h2 class="page-header__title">Record borrower payment</h2>
        <p class="page-header__subtitle">The payment number is generated automatically. Attach the proof of payment on the next page.</p>
    </div>
</section>

<?= render_record_form(url('pages/borrower_payments/create.php'), $fields, $data, $errors, 'Save payment', url('pages/borrower_payments/index.php')) ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
