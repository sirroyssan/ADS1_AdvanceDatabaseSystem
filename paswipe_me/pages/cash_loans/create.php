<?php
/**
 * Cash Loans - record a cash loan offer or loan.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/cash_loan_functions.php';

$currentUser = require_admin();

$fields = cash_loan_fields(true);
$preset = input_string($_GET, 'credit_card_id');
$data = form_defaults($fields, is_valid_uuid($preset) ? ['credit_card_id' => $preset] : []);
$errors = [];

if (is_post()) {
    require_csrf('pages/cash_loans/create.php');
    [$data, $errors] = cash_loan_validate($fields, $_POST);

    if (!$errors) {
        try {
            $id = new_uuid();
            $data['loan_reference'] ??= generate_reference('CL', 'cash_loans', 'loan_reference');
            db_insert('cash_loans', ['id' => $id] + $data + ['created_by' => $currentUser['id'], 'updated_by' => $currentUser['id']]);
            log_activity('create', 'cash_loan', $id, 'Recorded cash loan ' . $data['loan_reference'], null, $data);
            flash('success', 'Cash loan ' . $data['loan_reference'] . ' was recorded.');
            redirect('pages/cash_loans/view.php', ['id' => $id]);
        } catch (PDOException $e) {
            $errors['_form'] = db_error_message($e);
        }
    }
}

$pageTitle = 'Add Cash Loan';
$activeNav = 'cash_loans';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb(['Cash Loans' => url('pages/cash_loans/index.php'), 'Add' => null]) ?>

<section class="page-header">
    <div>
        <h2 class="page-header__title">Add cash loan</h2>
        <p class="page-header__subtitle">The repayment schedule and borrower allocations are managed on the loan page.</p>
    </div>
</section>

<?= render_record_form(url('pages/cash_loans/create.php'), $fields, $data, $errors, 'Save cash loan', url('pages/cash_loans/index.php')) ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
