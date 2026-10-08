<?php
/**
 * Installment Plans - create a plan for a transaction and generate its schedule.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/plan_functions.php';

$currentUser = require_admin();

$fields = plan_fields(true);
$preset = input_string($_GET, 'transaction_id');
$defaults = [];
if (is_valid_uuid($preset) && isset($fields['transaction_id']['options'][$preset])) {
    $txn = db_row('SELECT amount, transaction_date FROM transactions WHERE id = :id', [':id' => $preset]);
    $defaults = ['transaction_id' => $preset, 'total_principal' => $txn['amount'], 'start_date' => $txn['transaction_date']];
}
$data = form_defaults($fields, $defaults);
$errors = [];

if (is_post()) {
    require_csrf('pages/installment_plans/create.php');
    [$data, $errors] = plan_validate($fields, $_POST);

    if (!$errors) {
        try {
            $id = new_uuid();
            $planCard = db_value('SELECT credit_card_id FROM transactions WHERE id = :id', [':id' => $data['transaction_id']]);
            // Plan interest and fees are billed to the card, so recalculate its used credit.
            card_usage_apply([$planCard], function () use ($id, $data) {
                db_insert('installment_plans', ['id' => $id] + $data);
                plan_generate_schedule($id, $data);
            });
            log_activity('create', 'installment_plan', $id, 'Created ' . $data['number_of_installments'] . '-installment plan', null, $data);
            flash('success', 'Installment plan created with ' . $data['number_of_installments'] . ' scheduled payments.');
            redirect('pages/installment_plans/view.php', ['id' => $id]);
        } catch (PDOException $e) {
            $errors['_form'] = db_error_message($e);
        }
    }
}

$pageTitle = 'Add Installment Plan';
$activeNav = 'installments';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb(['Installment Plans' => url('pages/installment_plans/index.php'), 'Add' => null]) ?>

<section class="page-header">
    <div>
        <h2 class="page-header__title">Add installment plan</h2>
        <p class="page-header__subtitle">The payment schedule is generated automatically from these terms.</p>
    </div>
</section>

<?php if (!$fields['transaction_id']['options']): ?>
    <div class="alert alert--info"><?= icon('info') ?><span>Every open transaction already has a plan. <a href="<?= e(url('pages/transactions/create.php')) ?>">Record a transaction</a> first.</span></div>
<?php endif; ?>

<?= render_record_form(url('pages/installment_plans/create.php'), $fields, $data, $errors, 'Create plan', url('pages/installment_plans/index.php')) ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
