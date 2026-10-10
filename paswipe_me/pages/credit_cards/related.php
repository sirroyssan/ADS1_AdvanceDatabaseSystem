<?php
/**
 * Credit Cards - billing cycle actions (POST): add_cycle, delete_cycle.
 * card_billing_cycles has UNIQUE (credit_card_id, cycle_start_date, cycle_end_date).
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/credit_card_functions.php';

$currentUser = require_admin();

if (!is_post()) {
    redirect('pages/credit_cards/index.php');
}

$card = find_or_redirect(credit_card_find(request_id()), 'pages/credit_cards/index.php', 'Credit card');
$back = 'pages/credit_cards/view.php?id=' . $card['id'];
require_csrf($back);

try {
    switch (input_string($_POST, 'action')) {
        case 'add_cycle':
            [$data, $errors] = validate_fields(billing_cycle_fields(), $_POST);
            if (!$errors && $data['cycle_end_date'] < $data['cycle_start_date']) {
                $errors['cycle_end_date'] = 'Cycle end must be on or after the cycle start.';
            }
            if (!$errors && (int) db_value(
                'SELECT COUNT(*) FROM card_billing_cycles WHERE credit_card_id = :c AND cycle_start_date = :s AND cycle_end_date = :e',
                [':c' => $card['id'], ':s' => $data['cycle_start_date'], ':e' => $data['cycle_end_date']]
            ) > 0) {
                $errors['cycle_start_date'] = 'This billing cycle already exists.';
            }
            // A special cycle shapes periods that are not statements yet; statements keep their periods.
            $lastEnd = card_last_statement_end($card['id']);
            if (!$errors && $lastEnd && $data['cycle_start_date'] <= $lastEnd) {
                $errors['cycle_start_date'] = 'Statements already cover the dates up to ' . format_date($lastEnd)
                    . '. A new cycle must start after that (e.g. ' . format_date(date_add_days($lastEnd, 1)) . ').';
            }
            if ($errors) {
                flash('error', 'Billing cycle not saved: ' . reset($errors));
                break;
            }
            db_insert('card_billing_cycles', ['id' => new_uuid(), 'credit_card_id' => $card['id']] + $data);
            log_activity('add_cycle', 'credit_card', $card['id'], 'Added billing cycle ' . $data['cycle_start_date'] . ' to ' . $data['cycle_end_date']);
            flash('success', 'Billing cycle was added.');
            break;

        case 'delete_cycle':
            $cycle = db_row('SELECT * FROM card_billing_cycles WHERE id = :i AND credit_card_id = :c', [':i' => input_string($_POST, 'item_id'), ':c' => $card['id']]);
            if ($cycle && (int) db_value(
                'SELECT COUNT(*) FROM credit_card_statements WHERE credit_card_id = :c AND cutoff_date = :e AND COALESCE(period_start_date, :s) = :s',
                [':c' => $card['id'], ':e' => $cycle['cycle_end_date'], ':s' => $cycle['cycle_start_date']]
            ) > 0) {
                flash('error', 'This cycle belongs to a statement, so it is kept as history.');
                break;
            }
            $count = $cycle ? db_exec('DELETE FROM card_billing_cycles WHERE id = :i', [':i' => $cycle['id']]) : 0;
            if ($count) {
                log_activity('delete_cycle', 'credit_card', $card['id'], 'Deleted a billing cycle');
            }
            flash($count ? 'success' : 'error', $count ? 'Billing cycle was deleted.' : 'Billing cycle not found.');
            break;

        default:
            flash('error', 'Unknown action.');
    }
} catch (PDOException $e) {
    flash('error', db_error_message($e));
}

redirect($back);
