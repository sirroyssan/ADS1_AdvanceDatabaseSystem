<?php
/**
 * Cash Advances - create.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/cash_advance_functions.php';

$currentUser = require_admin();

$fields = cash_advance_fields();
$preset = input_string($_GET, 'credit_card_id');
$data = form_defaults($fields, is_valid_uuid($preset) ? ['credit_card_id' => $preset] : []);
// Opened from a statement ("Add missing transaction"): start from the
// statement's date and report afterwards which statement the record went to.
$reconcile = preg_match('/^\d{4}-\d{2}-\d{2}$/', input_string($_GET, 'period')) ? input_string($_GET, 'period') : null;   // cutoff of the statement period being viewed
if (!is_post() && preg_match('/^\d{4}-\d{2}-\d{2}$/', input_string($_GET, 'advance_date'))) {
    $data['advance_date'] = input_string($_GET, 'advance_date');
}
$errors = [];

if (is_post()) {
    require_csrf('pages/cash_advances/create.php');
    [$data, $errors] = cash_advance_validate($fields, $_POST);

    if (!$errors) {
        try {
            $id = new_uuid();
            card_usage_apply([$data['credit_card_id']], fn () => db_insert('cash_advances', ['id' => $id] + $data));
            log_activity('create', 'cash_advance', $id, 'Recorded cash advance of ' . format_money($data['amount']), null, $data);
            flash('success', 'Cash advance was recorded.');
            statement_placement_notice($data['credit_card_id'], $data['advance_date'], $reconcile);
            redirect('pages/cash_advances/view.php', ['id' => $id]);
        } catch (PDOException $e) {
            $errors['_form'] = db_error_message($e);
        }
    }
}

$pageTitle = 'Add Cash Advance';
$activeNav = 'cash_advances';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb(['Cash Advances' => url('pages/cash_advances/index.php'), 'Add' => null]) ?>

<section class="page-header">
    <div>
        <h2 class="page-header__title">Add cash advance</h2>
        <p class="page-header__subtitle">The total fills in automatically from amount, fee and interest.</p>
    </div>
</section>

<?= render_record_form(url('pages/cash_advances/create.php', array_filter(['period' => $reconcile])), $fields, $data, $errors, 'Save cash advance', url('pages/cash_advances/index.php')) ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
