<?php
/**
 * Cash Advances - edit (not allowed once cancelled).
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/cash_advance_functions.php';

$currentUser = require_admin();

$advance = find_or_redirect(cash_advance_find(request_id()), 'pages/cash_advances/index.php', 'Cash advance');

if ($advance['status'] === 'cancelled') {
    flash('error', 'A cancelled cash advance can no longer be edited.');
    redirect('pages/cash_advances/view.php', ['id' => $advance['id']]);
}

$fields = cash_advance_fields($advance);
$fields['total_amount']['attrs']['data-keep'] = '1';
$givenToBorrowers = cash_advance_given_total($advance);
if ($givenToBorrowers > 0) {
    // The card and reference number link this withdrawal to the borrowers' shares.
    foreach (['credit_card_id', 'reference_number'] as $locked) {
        $fields[$locked]['readonly'] = true;
        $fields[$locked]['help'] = 'Locked: part of this cash advance was given to borrowers.';
    }
}
$data = form_data_from_row($fields, $advance);
$errors = [];

if (is_post()) {
    require_csrf('pages/cash_advances/edit.php?id=' . $advance['id']);
    [$data, $errors] = cash_advance_validate($fields, $_POST, $advance['id']);
    if (!isset($errors['amount']) && $givenToBorrowers > 0 && (float) $data['amount'] + 0.004 < $givenToBorrowers) {
        $errors['amount'] = format_money($givenToBorrowers) . ' was already given to borrowers; the amount withdrawn cannot be lower.';
    }

    if (!$errors) {
        try {
            card_usage_apply([$advance['credit_card_id'], $data['credit_card_id'] ?? null], fn () => db_update('cash_advances', $data, $advance['id']));
            log_activity('update', 'cash_advance', $advance['id'], 'Updated cash advance', array_intersect_key($advance, $data), $data);
            flash('success', 'Cash advance was updated successfully.');
            statement_move_notice($advance['credit_card_id'], $advance['advance_date'], $data['credit_card_id'] ?? $advance['credit_card_id'], $data['advance_date'] ?? $advance['advance_date']);
            redirect('pages/cash_advances/view.php', ['id' => $advance['id']]);
        } catch (PDOException $e) {
            $errors['_form'] = db_error_message($e);
        }
    }
}

$pageTitle = 'Edit Cash Advance';
$activeNav = 'cash_advances';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb([
    'Cash Advances' => url('pages/cash_advances/index.php'),
    format_date($advance['advance_date']) => url('pages/cash_advances/view.php', ['id' => $advance['id']]),
    'Edit' => null,
]) ?>

<section class="page-header">
    <div>
        <h2 class="page-header__title">Edit cash advance</h2>
        <p class="page-header__subtitle">Set the status to Cancelled to void a mistaken entry.</p>
    </div>
</section>

<?= render_record_form(url('pages/cash_advances/edit.php', ['id' => $advance['id']]), $fields, $data, $errors, 'Save changes',
    url('pages/cash_advances/view.php', ['id' => $advance['id']])) ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
