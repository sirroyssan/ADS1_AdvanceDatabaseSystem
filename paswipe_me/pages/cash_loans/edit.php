<?php
/**
 * Cash Loans - edit. Amounts already split to borrowers cannot exceed the cash received.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/cash_loan_functions.php';

$currentUser = require_admin();

$loan = find_or_redirect(cash_loan_find(request_id()), 'pages/cash_loans/index.php', 'Cash loan');
$fields = cash_loan_fields(false, $loan);
$fields['monthly_payment']['attrs']['data-keep'] = '1';
$data = form_data_from_row($fields, $loan);
$errors = [];

if (is_post()) {
    require_csrf('pages/cash_loans/edit.php?id=' . $loan['id']);
    [$data, $errors] = cash_loan_validate($fields, $_POST, $loan['id']);

    if (!$errors && (float) $loan['allocated'] > 0 && cash_loan_distributable($data) + 0.004 < (float) $loan['allocated']) {
        $errors['actual_cash_received'] = format_money($loan['allocated']) . ' is already allocated to borrowers; the cash amount cannot be lower.';
    }

    if (!$errors) {
        try {
            card_usage_apply([$loan['credit_card_id'], $data['credit_card_id']], function () use ($loan, $data, $currentUser) {
                db_update('cash_loans', $data + ['updated_by' => $currentUser['id']], $loan['id']);
            }, true);
            log_activity('update', 'cash_loan', $loan['id'], 'Updated cash loan ' . $data['loan_reference'], array_intersect_key($loan, $data), $data);
            flash('success', 'Cash loan ' . $data['loan_reference'] . ' was updated. ' . cash_loan_usage_message($data));
            redirect('pages/cash_loans/view.php', ['id' => $loan['id']]);
        } catch (DomainException $e) {
            $errors['_form'] = $e->getMessage();
        } catch (PDOException $e) {
            $errors['_form'] = db_error_message($e);
        }
    }
}

$pageTitle = 'Edit Cash Loan';
$activeNav = 'cash_loans';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb([
    'Cash Loans' => url('pages/cash_loans/index.php'),
    $loan['loan_reference'] => url('pages/cash_loans/view.php', ['id' => $loan['id']]),
    'Edit' => null,
]) ?>

<section class="page-header">
    <div>
        <h2 class="page-header__title">Edit cash loan</h2>
        <p class="page-header__subtitle">Changing the terms does not regenerate an existing schedule.</p>
    </div>
</section>

<?= render_record_form(url('pages/cash_loans/edit.php', ['id' => $loan['id']]), $fields, $data, $errors, 'Save changes',
    url('pages/cash_loans/view.php', ['id' => $loan['id']])) ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
