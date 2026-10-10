<?php
/**
 * Statements - record a payment for a recorded SOA (opened in a modal from the
 * statement, the statement list or the dashboard). Uses the Card Payments
 * module's fields, validation and saving: the payment is one row in
 * credit_card_payments, linked to the card and to this statement.
 * A one-time form token stops a double click from saving it twice.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/statement_functions.php';
require_once APP_ROOT . '/pages/card_payments/card_payment_functions.php';

$currentUser = require_admin();

$statement = find_or_redirect(statement_find(request_id()), 'pages/statements/index.php', 'Statement');
$id = $statement['id'];
$card = db_row('SELECT * FROM credit_cards WHERE id = :c', [':c' => $statement['credit_card_id']]);

$allFields = card_payment_fields();
$shown = ['amount', 'payment_date', 'payment_method_id', 'reference_number', 'payment_channel', 'notes'];
$fields = array_intersect_key($allFields, array_flip($shown));
$fields['amount']['label'] = 'Payment amount';
$fields['payment_date']['label'] = 'Actual payment date';
$fields['payment_method_id']['label'] = 'Paid from (account)';
$data = form_defaults($fields, ['amount' => number_format((float) $statement['remaining'], 2, '.', '')]);
$errors = [];

if (is_post()) {
    require_csrf('pages/statements/pay.php?id=' . $id);
    $input = array_intersect_key($_POST, $fields) + ['credit_card_id' => $statement['credit_card_id'], 'statement_id' => $id];
    [$data, $errors] = card_payment_validate($allFields, $input);
    if (!$errors && !form_once_use('statement_pay_' . $id, input_string($_POST, 'once'))) {
        $errors['_form'] = 'This payment was already submitted (double click or resent form). Check the statement\'s payments before paying again.';
    }

    if (!$errors) {
        $amount = (float) $data['amount'];
        $remaining = (float) $statement['remaining'];
        $data['payment_type'] = match (true) {
            $amount >= $remaining - 0.004                        => 'Full statement balance',
            $amount >= (float) $statement['minimum_amount_due'] - 0.004 => 'Minimum or more (partial)',
            default                                              => 'Partial',
        };
        try {
            $paymentId = new_uuid();
            card_usage_apply([$data['credit_card_id']], fn () => db_insert('credit_card_payments', ['id' => $paymentId] + $data));
            log_activity('create', 'card_payment', $paymentId, 'Paid ' . format_money($amount) . ' on the ' . statement_label($statement) . ' SOA', null, $data);
            $left = round($remaining - $amount, 2);
            flash('success', 'Payment of ' . format_money($amount) . ' was recorded for ' . statement_label($statement) . '. '
                . ($left > 0.004 ? 'Still to pay: ' . format_money($left) . '.' : 'The statement is fully paid.'));
            if ($left < -0.004) {
                flash('info', 'This is ' . format_money(-$left) . ' more than the statement balance (kept as an overpayment).');
            }
            redirect('pages/statements/view.php', ['id' => $id]);
        } catch (PDOException $e) {
            $errors['_form'] = db_error_message($e);
        }
    }
}

$pageTitle = 'Pay Statement';
$activeNav = 'statements';
require APP_ROOT . '/includes/header.php';

$top = statement_card_context($card, null, [
    'Statement month'   => e(statement_month_label($statement['cutoff_date'] ?: $statement['statement_date'])),
    'SOA amount'        => '<strong>' . e(format_money($statement['total_amount_due'])) . '</strong>',
    'Minimum amount due' => e(format_money($statement['minimum_amount_due'])),
    'Payment due date'  => e(format_date($statement['due_date'])),
    'Paid so far'       => e(format_money($statement['paid'])) . ' <span class="text-muted">(' . e($statement['payment_count']) . ' payment(s))</span>',
    'Remaining'         => '<strong>' . e(format_money($statement['remaining'])) . '</strong> ' . statement_status_badge($statement['pay_status'], $statement['due_date']),
]) . '<input type="hidden" name="once" value="' . e(form_once_token('statement_pay_' . $id)) . '">';
?>

<?= render_breadcrumb([
    'Statements' => url('pages/statements/index.php'),
    statement_label($statement) => url('pages/statements/view.php', ['id' => $id]),
    'Pay' => null,
]) ?>

<section class="page-header">
    <div>
        <h2 class="page-header__title">Record payment</h2>
        <p class="page-header__subtitle"><?= e(statement_label($statement)) ?> · saved in Card Payments and linked to this statement</p>
    </div>
</section>

<?= render_record_form(url('pages/statements/pay.php', ['id' => $id]), $fields, $data, $errors, 'Save payment',
    url('pages/statements/view.php', ['id' => $id]), $top, 'pay-statement-form') ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
