<?php
/**
 * Transactions - edit (not allowed once cancelled or refunded).
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/transaction_functions.php';

$currentUser = require_admin();

$txn = find_or_redirect(transaction_find(request_id()), 'pages/transactions/index.php', 'Transaction');
if ($advanceOf = cash_share_advance($txn)) {
    flash('info', 'This is cash advance ' . $advanceOf['reference_number'] . ' given to a borrower. Change it on the cash advance.');
    redirect('pages/cash_advances/view.php', ['id' => $advanceOf['id']]);
}

if (in_array($txn['status'], TXN_LOCKED_STATUSES, true)) {
    flash('error', 'A ' . $txn['status'] . ' transaction can no longer be edited.');
    redirect('pages/transactions/view.php', ['id' => $txn['id']]);
}

// The installment fields are offered only while the transaction has no plan yet;
// an existing plan is managed from the Installment module.
$hasPlan = (int) db_value('SELECT COUNT(*) FROM installment_plans WHERE transaction_id = :id', [':id' => $txn['id']]) > 0;
$fields = transaction_fields($txn, !$hasPlan);
$fields['transaction_number']['required'] = true;
unset($fields['transaction_number']['help']);
$data = form_data_from_row($fields, $txn);
$errors = [];

if (is_post()) {
    require_csrf('pages/transactions/edit.php?id=' . $txn['id']);
    [$data, $errors] = transaction_validate($fields, $_POST, $txn['id']);
    $terms = null;
    if (!$hasPlan) {
        [$data, $terms, $errors] = transaction_installment_terms($data, $errors, false);
    }

    if (!$errors && $data['status'] === 'cancelled' && (int) db_value(
        // An automatic amount to repay with nothing repaid is cancelled together with the transaction.
        "SELECT COUNT(*) FROM borrower_obligations WHERE transaction_id = :id AND status <> 'cancelled'
            AND NOT (notes LIKE 'Created automatically: the borrower used your card%' AND total_paid = 0)", [':id' => $txn['id']]
    ) > 0) {
        $errors['status'] = 'Cancel the borrower obligations of this transaction first.';
    }

    if (!$errors) {
        try {
            $data = transaction_resolve_borrower($data);
            $planId = null;
            card_usage_apply([$txn['credit_card_id'], $data['credit_card_id'] ?? null], function () use ($txn, $data, $terms, $hasPlan, $currentUser, &$planId) {
                db_update('transactions', $data + ['updated_by' => $currentUser['id']], $txn['id']);
                if ($terms !== null && !$hasPlan) {
                    $planId = installment_plan_create(['id' => $txn['id'], 'transaction_date' => $data['transaction_date'] ?? $txn['transaction_date']], $terms, $terms['rule']);
                }
            });
            log_activity('update', 'transaction', $txn['id'], 'Updated transaction ' . $data['transaction_number'], array_intersect_key($txn, $data), $data);
            $newPlan = $planId ? db_row('SELECT number_of_installments, installment_amount, first_due_date FROM installment_plans WHERE id = :id', [':id' => $planId]) : null;
            borrowed_notify_update($txn, $txn['id'], $newPlan ? 'Installment terms: ' . $newPlan['number_of_installments'] . ' × ' . format_money($newPlan['installment_amount'])
                . ', first due ' . format_date($newPlan['first_due_date']) . '.' : '');
            flash('success', 'Transaction ' . $data['transaction_number'] . ' was updated successfully.');
            if ($planId) {
                $plan = db_row('SELECT * FROM installment_plans WHERE id = :id', [':id' => $planId]);
                log_activity('create', 'installment_plan', $planId, 'Created ' . $plan['number_of_installments'] . '-installment plan with transaction ' . $data['transaction_number'], null, $plan);
                flash('success', 'Installment plan added: ' . $plan['number_of_installments'] . ' × ' . format_money($plan['installment_amount'])
                    . ', first due ' . format_date($plan['first_due_date']) . ' (' . installment_terms_text($plan) . ').');
            }
            if ($repay = borrowed_after_save($txn['id'])) {
                flash('info', $repay);
            }
            statement_move_notice($txn['credit_card_id'], $txn['transaction_date'], $data['credit_card_id'] ?? $txn['credit_card_id'], $data['transaction_date'] ?? $txn['transaction_date']);
            redirect('pages/transactions/view.php', ['id' => $txn['id']]);
        } catch (DomainException $e) {
            $errors['borrower_email'] = $e->getMessage();
        } catch (PDOException $e) {
            $errors['_form'] = db_error_message($e);
        }
    }
}

$pageTitle = 'Edit Transaction';
$activeNav = 'transactions';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb([
    'Transactions' => url('pages/transactions/index.php'),
    $txn['transaction_number'] => url('pages/transactions/view.php', ['id' => $txn['id']]),
    'Edit' => null,
]) ?>

<section class="page-header">
    <div>
        <h2 class="page-header__title">Edit transaction</h2>
        <p class="page-header__subtitle">Last updated <?= e(format_datetime($txn['updated_at'])) ?></p>
    </div>
</section>

<?= render_record_form(url('pages/transactions/edit.php', ['id' => $txn['id']]), $fields, $data, $errors, 'Save changes',
    url('pages/transactions/view.php', ['id' => $txn['id']])) ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
