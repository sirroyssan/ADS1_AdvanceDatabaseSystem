<?php
/**
 * Cash Loans - actions (POST): generate_schedule, record_payment, schedule_status,
 * add_allocation, delete_allocation, create_obligation.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/cash_loan_functions.php';

$currentUser = require_admin();

if (!is_post()) {
    redirect('pages/cash_loans/index.php');
}

$loan = find_or_redirect(cash_loan_find(request_id()), 'pages/cash_loans/index.php', 'Cash loan');
$back = 'pages/cash_loans/view.php?id=' . $loan['id'];
require_csrf($back);

if (in_array($loan['status'], ['cancelled', 'rejected', 'completed'], true)) {
    flash('error', 'A ' . $loan['status'] . ' cash loan can no longer be changed.');
    redirect($back);
}

$itemId = input_string($_POST, 'item_id');

try {
    switch (input_string($_POST, 'action')) {
        case 'shift_dates':
            // "Change due dates": move the existing schedule to a new monthly due day.
            [$data, $errors] = validate_fields(schedule_shift_fields(), $_POST);
            if ($errors) {
                flash('error', 'Due dates not changed: ' . reset($errors));
                break;
            }
            $moved = db_transaction(static function () use ($loan, $data) {
                $newEnd = schedule_shift_dates('cash_loan_schedules', 'cash_loan_id', $loan['id'], $data['first_due_date'], $data['frequency']);
                if ($newEnd === null) {
                    return null;
                }
                db_update('cash_loans', ['first_due_date' => $data['first_due_date'], 'payment_frequency' => $data['frequency'], 'end_date' => $newEnd], $loan['id']);
                schedule_move_obligations(array_column(db_all(
                    "SELECT o.id FROM borrower_obligations o JOIN cash_loan_allocations a ON a.id = o.cash_loan_allocation_id
                      WHERE a.cash_loan_id = :l AND o.status <> 'cancelled'", [':l' => $loan['id']]), 'id'), $loan['end_date'], $newEnd);

                return $newEnd;
            });
            if ($moved === null) {
                flash('error', 'This loan has no repayment schedule yet. Generate it first.');
                break;
            }
            log_activity('shift_dates', 'cash_loan', $loan['id'], 'Due dates moved: first ' . $data['first_due_date'] . ', last ' . $moved,
                ['first_due_date' => $loan['first_due_date'], 'end_date' => $loan['end_date']], ['first_due_date' => $data['first_due_date'], 'end_date' => $moved]);
            flash('success', 'Due dates updated: first installment due ' . format_date($data['first_due_date']) . ', last ' . format_date($moved) . '. Amounts and payments were kept.');
            break;

        case 'generate_schedule':
            if ((int) db_value('SELECT COUNT(*) FROM cash_loan_schedules WHERE cash_loan_id = :id', [':id' => $loan['id']]) > 0) {
                flash('error', 'A schedule already exists.');
                break;
            }
            $count = db_transaction(static fn () => cash_loan_generate_schedule($loan));
            if ($count === 0) {
                flash('error', 'Enter the term, first due date and total repayment first.');
                break;
            }
            log_activity('generate_schedule', 'cash_loan', $loan['id'], 'Generated ' . $count . '-row schedule');
            flash('success', 'Repayment schedule with ' . $count . ' installments was generated.');
            break;

        case 'record_payment':
        case 'schedule_status':
            // Installments are paid only through borrower payments (applied automatically).
            flash('error', 'Installments are updated automatically from approved borrower payments. Record the payment under Borrower Payments.');
            break;

        case 'add_allocation':
            [$data, $errors] = validate_fields(allocation_fields(), $_POST);
            $errors = allocation_validate($data, $errors);
            $room = cash_loan_distributable($loan) - (float) $loan['allocated'];
            if (!$errors && (float) $data['allocated_amount'] > $room + 0.004) {
                $errors['allocated_amount'] = 'Only ' . format_money(max(0, $room)) . ' of the loan is not yet allocated.';
            }
            if ($errors) {
                flash('error', 'Allocation not saved: ' . reset($errors));
                break;
            }
            // An "owes me" part becomes the person's obligation at once (status active), over the loan's term.
            $created = db_transaction(static function () use ($loan, $data, $currentUser) {
                $allocationId = new_uuid();
                db_insert('cash_loan_allocations', ['id' => $allocationId, 'cash_loan_id' => $loan['id']] + $data);
                return cash_loan_allocation_obligation($loan, $allocationId, $currentUser['id']);
            });
            log_activity('add_allocation', 'cash_loan', $loan['id'], 'Allocated ' . format_money($data['allocated_amount']), null, $data);
            if ($created) {
                log_activity('create', 'obligation', $created[0], 'Created obligation ' . $created[1] . ' from cash loan ' . $loan['loan_reference']);
                penalty_assign_on_create($created[0]);
                $due = db_value('SELECT due_date FROM borrower_obligations WHERE id = :id', [':id' => $created[0]]);
                flash('success', 'Allocation of ' . format_money($data['allocated_amount']) . ' was added. Obligation ' . $created[1] . ' was created (active)'
                    . ($created[2] ? ': ' . $created[2] . ' monthly installments' : '') . ($due ? ', last one due ' . format_date($due) : '') . '.');
                if (!$due) {
                    flash('warning', 'The loan has no term and first due date yet, so the obligation has no due date. Enter them on the loan; the obligation follows.');
                }
            } else {
                flash('success', 'Allocation of ' . format_money($data['allocated_amount']) . ' was added.');
            }
            break;

        case 'delete_allocation':
            $count = db_exec('DELETE FROM cash_loan_allocations WHERE id = :i AND cash_loan_id = :l AND obligation_id IS NULL', [':i' => $itemId, ':l' => $loan['id']]);
            flash($count ? 'success' : 'error', $count ? 'Allocation was removed.' : 'An allocation with an obligation cannot be removed.');
            break;

        case 'create_obligation':
            // Older allocations made before obligations were created automatically.
            $result = db_transaction(static fn () => cash_loan_allocation_obligation($loan, $itemId, $currentUser['id']));
            if ($result === null) {
                flash('error', 'This allocation cannot be turned into an obligation.');
                break;
            }
            log_activity('create', 'obligation', $result[0], 'Created obligation ' . $result[1] . ' from cash loan ' . $loan['loan_reference']);
            penalty_assign_on_create($result[0]);
            flash('success', 'Obligation ' . $result[1] . ' was created (active).');
            break;

        default:
            flash('error', 'Unknown action.');
    }
} catch (DomainException $e) {
    flash('error', $e->getMessage());
} catch (PDOException $e) {
    flash('error', db_error_message($e));
}

redirect($back);
