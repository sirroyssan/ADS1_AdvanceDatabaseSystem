<?php
/**
 * Cash Advances - give (part of) the withdrawn cash to a borrower (POST).
 * Creates the borrower's obligation with fee and monthly interest, payable monthly.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/cash_advance_functions.php';

$currentUser = require_admin();

if (!is_post()) {
    redirect('pages/cash_advances/index.php');
}

$advance = find_or_redirect(cash_advance_find(request_id()), 'pages/cash_advances/index.php', 'Cash advance');
$back = 'pages/cash_advances/view.php?id=' . $advance['id'];
require_csrf($back);

$remaining = (float) $advance['amount'] - cash_advance_given_total($advance);
[$data, $errors] = validate_fields(cash_advance_give_fields($advance, $remaining), $_POST);
if (!$errors && $data['first_due_date'] <= $data['given_date']) {
    $errors['first_due_date'] = 'The first payment must be due after the date given.';
}
if ($errors) {
    flash('error', 'Not saved: ' . reset($errors));
    $_SESSION['give_form'] = $_POST;
    redirect($back . '#give-borrower');
}

try {
    [$obligationId, $number, $t] = cash_advance_give($advance, $data, $currentUser);
    log_activity('give_cash_advance', 'cash_advance', $advance['id'],
        'Gave ' . format_money($data['amount']) . ' to a borrower; obligation ' . $number . ' of ' . format_money($t['total']), null, $data);
    log_activity('create', 'obligation', $obligationId, 'Created obligation ' . $number . ' from cash advance ' . ($advance['reference_number'] ?: ''), null, $data);
    penalty_assign_on_create($obligationId);
    notify_borrower($data['borrower_id'], 'obligation_created', 'New item: ' . $number,
        'Cash advance of ' . format_money($data['amount']) . ' with fee and interest: ' . format_money($t['total']) . ' in ' . (int) $data['months']
        . ' monthly payment(s) of ' . format_money($t['monthly']) . ', first due ' . format_date($data['first_due_date']) . '.', 'obligation', $obligationId);
    flash('success', 'Obligation ' . $number . ' was created: ' . format_money($t['total']) . ' (' . (int) $data['months'] . ' × ' . format_money($t['monthly']) . ').');
} catch (DomainException $e) {
    flash('error', $e->getMessage());
    $_SESSION['give_form'] = $_POST;
    redirect($back . '#give-borrower');
} catch (PDOException $e) {
    flash('error', db_error_message($e));
}

redirect($back);
