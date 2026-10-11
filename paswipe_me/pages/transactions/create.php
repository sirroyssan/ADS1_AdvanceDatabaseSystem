<?php
/**
 * Transactions - record a card swipe.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/transaction_functions.php';

$currentUser = require_staff_area();

$fields = transaction_fields();
$preset = [];
foreach (['credit_card_id', 'borrower_id'] as $key) {
    $value = input_string($_GET, $key);
    if (owns($key === 'credit_card_id' ? 'credit_cards' : 'borrowers', $value)) {   // prefill only the user's own card / borrower
        $preset[$key] = $value;
    }
}
$data = form_defaults($fields, $preset);
// Opened from a statement ("Add missing transaction"): start from the
// statement's date and report afterwards which statement the record went to.
$reconcile = preg_match('/^\d{4}-\d{2}-\d{2}$/', input_string($_GET, 'period')) ? input_string($_GET, 'period') : null;   // cutoff of the statement period being viewed
if (!is_post() && preg_match('/^\d{4}-\d{2}-\d{2}$/', input_string($_GET, 'transaction_date'))) {
    $data['transaction_date'] = input_string($_GET, 'transaction_date');
}
$errors = [];

if (is_post()) {
    require_csrf('pages/transactions/create.php');
    [$data, $errors] = transaction_validate($fields, $_POST);
    [$data, $terms, $errors] = transaction_installment_terms($data, $errors);
    // The same form sent twice (double click, refresh) saves once.
    if (!$errors && !form_once_use('transaction_create', input_string($_POST, 'once'))) {
        flash('info', 'This transaction was already saved.');
        redirect('pages/transactions/index.php');
    }

    if (!$errors) {
        try {
            $id = new_uuid();
            $data['transaction_number'] ??= generate_reference('TXN', 'transactions', 'transaction_number');
            $data = transaction_resolve_borrower($data);
            $planId = null;
            // One database transaction: the purchase, and for an installment purchase its plan and schedule.
            card_usage_apply([$data['credit_card_id']], function () use ($id, $data, $terms, $currentUser, &$planId) {
                db_insert('transactions', ['id' => $id] + $data + ['created_by' => $currentUser['id'], 'updated_by' => $currentUser['id']]);
                if ($terms !== null) {
                    $planId = installment_plan_create(['id' => $id, 'transaction_date' => $data['transaction_date']], $terms, $terms['rule']);
                }
            });
            log_activity('create', 'transaction', $id, 'Recorded transaction ' . $data['transaction_number'] . ' for ' . format_money($data['amount']), null, $data);
            if ($planId) {
                $plan = db_row('SELECT * FROM installment_plans WHERE id = :id', [':id' => $planId]);
                log_activity('create', 'installment_plan', $planId, 'Created ' . $plan['number_of_installments'] . '-installment plan with transaction ' . $data['transaction_number'], null, $plan);
                flash('success', 'Transaction ' . $data['transaction_number'] . ' of ' . format_money($data['amount']) . ' was recorded with its installment plan: '
                    . $plan['number_of_installments'] . ' × ' . format_money($plan['installment_amount']) . ', first due ' . format_date($plan['first_due_date']) . ' (' . installment_terms_text($plan) . ').');
            } else {
                flash('success', 'Transaction ' . $data['transaction_number'] . ' was recorded. You can now add the purchased items.');
            }
            if ($repay = borrowed_after_save($id)) {
                flash('info', $repay);
            }
            statement_placement_notice($data['credit_card_id'], $data['transaction_date'], $reconcile);
            redirect('pages/transactions/view.php', ['id' => $id]);
        } catch (DomainException $e) {
            $errors['borrower_email'] = $e->getMessage();
        } catch (PDOException $e) {
            $errors['_form'] = db_error_message($e);
        }
    }
}

$pageTitle = 'Add Transaction';
$activeNav = 'transactions';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb(['Transactions' => url('pages/transactions/index.php'), 'Add' => null]) ?>

<section class="page-header">
    <div>
        <h2 class="page-header__title">Add transaction</h2>
        <p class="page-header__subtitle">Choose Installment as the payment type to save the installment plan and its schedule together with the transaction. Products and receipts are added after saving.</p>
    </div>
</section>

<?= render_record_form(url('pages/transactions/create.php', array_filter(['period' => $reconcile])), $fields, $data, $errors, 'Save transaction', url('pages/transactions/index.php'),
    form_once_field('transaction_create')) ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
