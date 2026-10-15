<?php
/**
 * Borrower Payments - delete (void) a payment (modal form). OWNER ONLY.
 * A payment is never erased: everything it paid is given back (balances,
 * installments and penalties restored), it becomes cancelled and stays in the
 * history with the reason; its receipt is marked void or amended.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once APP_ROOT . '/includes/authorizations.php';

$currentUser = require_admin();

$payment = find_or_redirect(payment_find(request_id()), 'pages/borrower_payments/index.php', 'Payment');
$back = 'pages/borrower_payments/view.php?id=' . $payment['id'];
if ($payment['status'] === 'cancelled') {
    flash('info', 'This payment is already void.');
    redirect($back);
}
$receipt = db_value('SELECT r.receipt_number FROM payment_receipt_items i JOIN payment_receipts r ON r.id = i.receipt_id WHERE i.borrower_payment_id = :p', [':p' => $payment['id']]);
$fields = ['reason' => ['label' => 'Reason for deleting', 'type' => 'textarea', 'rows' => 3, 'required' => true, 'max' => 1000,
                        'help' => 'At least ' . AUTH_REASON_MIN . ' characters. Kept in the payment history; the borrower is notified.']];
$data = ['reason' => ''];
$errors = [];

if (is_post()) {
    require_csrf('pages/borrower_payments/void.php?id=' . $payment['id']);
    $data['reason'] = trim(input_string($_POST, 'reason'));
    if (mb_strlen($data['reason']) < AUTH_REASON_MIN) {
        $errors['reason'] = 'Explain why in at least ' . AUTH_REASON_MIN . ' characters.';
    } else {
        try {
            $result = db_transaction(static function () use ($payment, $data, $currentUser) {
                $fresh = db_row('SELECT * FROM borrower_payments WHERE id = :id FOR UPDATE', [':id' => $payment['id']]);
                if ($fresh['status'] === 'cancelled') {
                    throw new DomainException('This payment is already void.');
                }

                return payment_void($fresh, $data['reason'], $currentUser);
            });
            flash('success', $result);
            redirect($back);
        } catch (DomainException $e) {
            $errors['_form'] = $e->getMessage();
        } catch (PDOException $e) {
            $errors['_form'] = db_error_message($e);
        }
    }
}

$pageTitle = 'Delete Payment';
$activeNav = 'borrower_payments';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb(['Borrower Payments' => url('pages/borrower_payments/index.php'), $payment['payment_number'] => url($back), 'Delete' => null]) ?>

<section class="page-header">
    <div>
        <h2 class="page-header__title">Delete payment <?= e($payment['payment_number']) ?></h2>
        <p class="page-header__subtitle">The payment is voided, not erased: what it paid is owed again, and it stays in the history with your reason.</p>
    </div>
</section>

<div class="alert alert--warning" role="note"><?= icon('alert') ?><span>Voiding cannot be undone. The borrower is notified.</span></div>
<?= render_details([
    'Borrower'        => e(borrower_name($payment) . ' (' . $payment['borrower_number'] . ')'),
    'Receipt no.'     => show($receipt ?: null),
    'Amount tendered' => '<strong>' . e(format_money($payment['amount'])) . '</strong>',
    'Applied'         => e(format_money($payment['allocated'])) . ($payment['transaction_id'] ? ' <span class="text-muted">(card purchase repayment)</span>' : ''),
    'Payment date'    => e(format_date($payment['payment_date'])),
    'Status'          => badge($payment['status']),
]) ?>

<?= render_record_form(url('pages/borrower_payments/void.php', ['id' => $payment['id']]), $fields, $data, $errors, 'Delete payment', url($back)) ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
