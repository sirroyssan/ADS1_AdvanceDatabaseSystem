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
            if (!$loan['term_months'] || !$loan['first_due_date'] || (float) $loan['total_repayment'] <= 0) {
                flash('error', 'Enter the term, first due date and total repayment first.');
                break;
            }
            // Same principal as the card usage calculation.
            $loanPrincipal = cash_loan_principal($loan);
            $principal = $loanPrincipal > 0 ? min((float) $loan['total_repayment'], $loanPrincipal) : (float) $loan['total_repayment'];
            $charges = (float) $loan['total_repayment'] - $principal;
            $rows = build_schedule($principal, $charges, 0, (int) $loan['term_months'], $loan['first_due_date'], $loan['payment_frequency'] ?? 'monthly');
            db_transaction(function () use ($loan, $rows) {
                foreach ($rows as $row) {
                    db_insert('cash_loan_schedules', ['id' => new_uuid(), 'cash_loan_id' => $loan['id']] + $row);
                }
                if (!$loan['end_date']) {
                    db_update('cash_loans', ['end_date' => end($rows)['due_date']], $loan['id']);
                }
            });
            log_activity('generate_schedule', 'cash_loan', $loan['id'], 'Generated ' . count($rows) . '-row schedule');
            flash('success', 'Repayment schedule with ' . count($rows) . ' installments was generated.');
            break;

        case 'record_payment':
        case 'schedule_status':
            // Installments are paid only through borrower payments (applied automatically).
            flash('error', 'Installments are updated automatically from approved borrower payments. Record the payment under Borrower Payments.');
            break;

        case 'add_allocation':
            [$data, $errors] = validate_fields(allocation_fields(), $_POST);
            $room = cash_loan_distributable($loan) - (float) $loan['allocated'];
            if (!$errors && (float) $data['allocated_amount'] > $room + 0.004) {
                $errors['allocated_amount'] = 'Only ' . format_money(max(0, $room)) . ' of the loan is not yet allocated.';
            }
            if ($errors) {
                flash('error', 'Allocation not saved: ' . reset($errors));
                break;
            }
            db_insert('cash_loan_allocations', ['id' => new_uuid(), 'cash_loan_id' => $loan['id']] + $data);
            log_activity('add_allocation', 'cash_loan', $loan['id'], 'Allocated ' . format_money($data['allocated_amount']), null, $data);
            flash('success', 'Allocation of ' . format_money($data['allocated_amount']) . ' was added.');
            break;

        case 'delete_allocation':
            $count = db_exec('DELETE FROM cash_loan_allocations WHERE id = :i AND cash_loan_id = :l AND obligation_id IS NULL', [':i' => $itemId, ':l' => $loan['id']]);
            flash($count ? 'success' : 'error', $count ? 'Allocation was removed.' : 'An allocation with an obligation cannot be removed.');
            break;

        case 'create_obligation':
            $result = db_transaction(function () use ($loan, $itemId, $currentUser) {
                $allocation = db_row('SELECT * FROM cash_loan_allocations WHERE id = :i AND cash_loan_id = :l FOR UPDATE', [':i' => $itemId, ':l' => $loan['id']]);
                if (!$allocation || !$allocation['borrower_id'] || $allocation['obligation_id']) {
                    return null;
                }
                $obligationId = new_uuid();
                $number = generate_reference('OBL', 'borrower_obligations', 'obligation_number');
                db_insert('borrower_obligations', [
                    'id' => $obligationId, 'obligation_number' => $number, 'borrower_id' => $allocation['borrower_id'],
                    'cash_loan_allocation_id' => $allocation['id'],
                    'description' => 'Cash loan ' . $loan['loan_reference'] . ($allocation['description'] ? ' — ' . $allocation['description'] : ''),
                    'original_amount' => $allocation['allocated_amount'], 'total_amount_due' => $allocation['allocated_amount'],
                    'total_paid' => '0.00', 'outstanding_amount' => $allocation['allocated_amount'], 'status' => 'active',
                    'obligation_date' => date('Y-m-d'), 'due_date' => $loan['end_date'],
                    'created_by' => $currentUser['id'], 'updated_by' => $currentUser['id'],
                ]);
                db_update('cash_loan_allocations', ['obligation_id' => $obligationId], $allocation['id']);
                return [$obligationId, $number];
            });
            if ($result === null) {
                flash('error', 'This allocation cannot be turned into an obligation.');
                break;
            }
            log_activity('create', 'obligation', $result[0], 'Created obligation ' . $result[1] . ' from cash loan ' . $loan['loan_reference']);
            penalty_assign_on_create($result[0]);
            flash('success', 'Obligation ' . $result[1] . ' was created. Adjust interest or due date there if agreed.');
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
