<?php
/**
 * Obligations - edit terms. The total due may not drop below what is already paid.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/obligation_functions.php';

$currentUser = require_admin();

$obligation = find_or_redirect(obligation_find(request_id()), 'pages/obligations/index.php', 'Obligation');

if (in_array($obligation['status'], ['cancelled', 'written_off', 'paid'], true)) {
    flash('error', 'A ' . humanize($obligation['status']) . ' obligation can no longer be edited.');
    redirect('pages/obligations/view.php', ['id' => $obligation['id']]);
}

$fields = obligation_fields(false, $obligation);
$data = form_data_from_row($fields, $obligation);
$errors = [];

if (is_post()) {
    require_csrf('pages/obligations/edit.php?id=' . $obligation['id']);
    [$data, $errors] = obligation_validate($fields, $_POST, $obligation['id'], (float) $obligation['total_paid'], $obligation['borrower_id']);

    if (!$errors) {
        try {
            db_transaction(static function () use (&$data, &$errors, $obligation, $currentUser) {
                // A cash loan share: the allocation on the cash loan and the description follow the amount.
                if ($obligation['cash_loan_allocation_id']) {
                    require_once APP_ROOT . '/pages/cash_loans/cash_loan_functions.php';
                    [$data['description'], $error] = cash_loan_allocation_follow($obligation, (float) $data['original_amount'], (string) $data['description']);
                    if ($error !== null) {
                        throw new DomainException($error);
                    }
                }
                db_update('borrower_obligations', $data + ['updated_by' => $currentUser['id']], $obligation['id']);
                schedule_sync_obligation($obligation['id']);
            });
            log_activity('update', 'obligation', $obligation['id'], 'Updated obligation ' . $data['obligation_number'], array_intersect_key($obligation, $data), $data);
            flash('success', 'Obligation ' . $data['obligation_number'] . ' was updated.');
            redirect('pages/obligations/view.php', ['id' => $obligation['id']]);
        } catch (DomainException $e) {
            $errors['original_amount'] = $e->getMessage();
        } catch (PDOException $e) {
            $errors['_form'] = db_error_message($e);
        }
    }
}

$pageTitle = 'Edit Obligation';
$activeNav = 'obligations';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb([
    'Obligations' => url('pages/obligations/index.php'),
    $obligation['obligation_number'] => url('pages/obligations/view.php', ['id' => $obligation['id']]),
    'Edit' => null,
]) ?>

<section class="page-header">
    <div>
        <h2 class="page-header__title">Edit obligation</h2>
        <p class="page-header__subtitle">Already paid: <?= e(format_money($obligation['total_paid'])) ?>. Status is changed from the obligation page.</p>
    </div>
</section>

<?= render_record_form(url('pages/obligations/edit.php', ['id' => $obligation['id']]), $fields, $data, $errors, 'Save changes',
    url('pages/obligations/view.php', ['id' => $obligation['id']])) ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
