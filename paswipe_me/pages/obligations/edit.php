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
                // Wrong person picked: move the obligation (and its cash loan share) to the right borrower.
                if (isset($data['borrower_id']) && $data['borrower_id'] !== $obligation['borrower_id']) {
                    $locked = db_row('SELECT * FROM borrower_obligations WHERE id = :id FOR UPDATE', [':id' => $obligation['id']]);
                    $reason = obligation_borrower_lock($locked);
                    if ($reason !== null || !owns('borrowers', $data['borrower_id'])) {
                        throw new DomainException('borrower_id|The borrower cannot be changed: ' . ($reason ?? 'choose one of your borrowers.'));
                    }
                    if ($obligation['cash_loan_allocation_id']) {
                        db_update('cash_loan_allocations', ['borrower_id' => $data['borrower_id']], $obligation['cash_loan_allocation_id']);
                    }
                }
                // A cash loan share: the allocation on the cash loan and the description follow the amount.
                if ($obligation['cash_loan_allocation_id']) {
                    require_once APP_ROOT . '/pages/cash_loans/cash_loan_functions.php';
                    [$data['description'], $error] = cash_loan_allocation_follow($obligation, (float) $data['original_amount'], (string) $data['description']);
                    if ($error !== null) {
                        throw new DomainException('original_amount|' . $error);
                    }
                }
                db_update('borrower_obligations', $data + ['updated_by' => $currentUser['id']], $obligation['id']);
                schedule_sync_obligation($obligation['id']);
            });
            log_activity('update', 'obligation', $obligation['id'], 'Updated obligation ' . $data['obligation_number'], array_intersect_key($obligation, $data), $data);
            if (isset($data['borrower_id']) && $data['borrower_id'] !== $obligation['borrower_id']) {
                $to = db_row('SELECT first_name, last_name FROM borrowers WHERE id = :b', [':b' => $data['borrower_id']]);
                log_activity('change_borrower', 'obligation', $obligation['id'], 'Obligation ' . $data['obligation_number'] . ' moved from '
                    . borrower_name($obligation) . ' to ' . borrower_name($to), ['borrower_id' => $obligation['borrower_id']], ['borrower_id' => $data['borrower_id']]);
                notify_borrower($data['borrower_id'], 'obligation_created', 'New item: ' . $data['obligation_number'],
                    format_money($data['total_amount_due']) . ' was added to your balance' . ($data['due_date'] ? ', due ' . format_date($data['due_date']) : '') . '.', 'obligation', $obligation['id']);
                flash('info', 'Moved to ' . borrower_name($to) . '.');
            }
            flash('success', 'Obligation ' . $data['obligation_number'] . ' was updated.');
            redirect('pages/obligations/view.php', ['id' => $obligation['id']]);
        } catch (DomainException $e) {
            [$field, $message] = explode('|', $e->getMessage(), 2) + [1 => $e->getMessage()];
            $errors[str_contains($e->getMessage(), '|') ? $field : 'original_amount'] = $message;
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
