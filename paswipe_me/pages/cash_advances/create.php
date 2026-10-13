<?php
/**
 * Cash Advances - create.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/cash_advance_functions.php';

$currentUser = require_admin();

$fields = cash_advance_fields();
$preset = input_string($_GET, 'credit_card_id');
$data = form_defaults($fields, owns('credit_cards', $preset) ? ['credit_card_id' => $preset] : []);
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
            $borrowerId = $data['borrower_id'] ?? null;
            unset($data['borrower_id']);   // kept on the borrower's transaction, not on the advance
            if ($borrowerId !== null && !$data['reference_number']) {
                $data['reference_number'] = generate_reference('CA', 'cash_advances', 'reference_number');   // links the advance and the borrower's share
            }
            $note = card_usage_apply([$data['credit_card_id']], function () use ($id, $data, $borrowerId, $currentUser) {
                db_insert('cash_advances', ['id' => $id] + $data);

                return cash_advance_sync_borrower(db_row('SELECT * FROM cash_advances WHERE id = :id', [':id' => $id]), $borrowerId, $currentUser);
            });
            log_activity('create', 'cash_advance', $id, 'Recorded cash advance of ' . format_money($data['amount']), null, $data + ['borrower_id' => $borrowerId]);
            flash('success', trim('Cash advance was recorded. ' . $note . ' It is listed in ' . statement_for_date($data['credit_card_id'], $data['advance_date'])['label'] . '.'));
            statement_placement_notice($data['credit_card_id'], $data['advance_date'], $reconcile);
            redirect('pages/cash_advances/view.php', ['id' => $id]);
        } catch (DomainException $e) {
            $errors['_form'] = $e->getMessage();
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
