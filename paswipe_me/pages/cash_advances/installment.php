<?php
/**
 * Cash Advances - convert the borrower's cash advance into monthly installments
 * with interest (opened in a modal from the cash advance page). Creates an
 * installment plan on the borrower's transaction - no obligation - listed in
 * Installment Plans. Only before any repayment.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/cash_advance_functions.php';

$currentUser = require_admin();

$advance = find_or_redirect(cash_advance_find(request_id()), 'pages/cash_advances/index.php', 'Cash advance');
$back = 'pages/cash_advances/view.php?id=' . $advance['id'];
$share = cash_advance_direct_share($advance);
if ($block = cash_advance_convert_block($advance, $share)) {
    flash('error', $block);
    redirect($back);
}

$fields = cash_advance_installment_fields();
$ledger = (borrowed_owner_purchases(null, $share['id'])[0] ?? [])['ledger'] ?? null;
$data = form_defaults($fields, [
    'months' => 3,
    // The first installment is due on the card's next payment due date after the advance.
    'first_due_date' => $ledger['next_due']['date'] ?? schedule_due_date($share['transaction_date'], 1, 'monthly'),
]);
$errors = [];

if (is_post()) {
    require_csrf($back);
    [$data, $errors] = validate_fields($fields, $_POST);
    if (!$errors && $data['first_due_date'] <= $share['transaction_date']) {
        $errors['first_due_date'] = 'The first installment must be due after the cash advance date.';
    }
    if (!$errors) {
        try {
            $planId = cash_advance_convert($advance, $data, $currentUser);
            log_activity('convert_installment', 'cash_advance', $advance['id'],
                'Cash advance of ' . borrower_name($share) . ' converted to ' . $data['months'] . ' monthly installment(s) at ' . (float) $data['monthly_rate'] . '% per month',
                null, $data + ['installment_plan_id' => $planId]);
            flash('success', 'The cash advance is now paid in ' . $data['months'] . ' monthly installment(s). It is listed in Installment Plans.');
            redirect($back);
        } catch (DomainException $e) {
            $errors['_form'] = $e->getMessage();
        } catch (PDOException $e) {
            $errors['_form'] = db_error_message($e);
        }
    }
}

$pageTitle = 'Convert to Installment';
$activeNav = 'cash_advances';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb([
    'Cash Advances' => url('pages/cash_advances/index.php'),
    format_date($advance['advance_date']) => url($back),
    'Convert to installment' => null,
]) ?>

<section class="page-header">
    <div>
        <h2 class="page-header__title">Convert to installment</h2>
        <p class="page-header__subtitle"><?= e(borrower_name($share)) ?> · <?= e(format_money($share['amount'])) ?> cash advance of <?= e(format_date($share['transaction_date'])) ?></p>
    </div>
</section>

<div class="alert alert--info"><?= icon('info') ?><span>The borrower repays <?= e(format_money($share['amount'])) ?> + interest (amount × rate × months) + any fee, in equal monthly installments.
    Each installment is due on the card's payment due date of its month; late installments are charged by your penalty rules. No obligation is created.</span></div>

<?= render_record_form(url('pages/cash_advances/installment.php', ['id' => $advance['id']]), $fields, $data, $errors, 'Convert', url($back)) ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
