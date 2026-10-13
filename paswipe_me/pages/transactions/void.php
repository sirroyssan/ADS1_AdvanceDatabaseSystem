<?php
/**
 * Transactions - delete (void) a confirmed transaction (modal form). OWNER ONLY.
 * The record is kept with status cancelled: it leaves the card's used credit,
 * its installment plan and unpaid obligations are cancelled. Refused while
 * borrower repayments still count on it (void those first). Drafts are deleted
 * outright from the transaction page instead.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once APP_ROOT . '/includes/authorizations.php';

$currentUser = require_admin();

$txn = find_or_redirect(transaction_find(request_id()), 'pages/transactions/index.php', 'Transaction');
if ($advanceOf = cash_share_advance($txn)) {
    flash('info', 'This is cash advance ' . $advanceOf['reference_number'] . ' given to a borrower. Change it on the cash advance.');
    redirect('pages/cash_advances/view.php', ['id' => $advanceOf['id']]);
}
$back = 'pages/transactions/view.php?id=' . $txn['id'];
if (in_array($txn['status'], TXN_LOCKED_STATUSES, true)) {
    flash('info', 'This transaction is already ' . $txn['status'] . '.');
    redirect($back);
}
$problem = auth_delete_problem('transaction', ['record' => $txn]);
$fields = ['reason' => ['label' => 'Reason for deleting', 'type' => 'textarea', 'rows' => 3, 'required' => true, 'max' => 1000,
                        'help' => 'At least ' . AUTH_REASON_MIN . ' characters. Kept in the activity log.']];
$data = ['reason' => ''];
$errors = $problem ? ['_form' => $problem] : [];

if (is_post() && !$problem) {
    require_csrf('pages/transactions/void.php?id=' . $txn['id']);
    $data['reason'] = trim(input_string($_POST, 'reason'));
    if (mb_strlen($data['reason']) < AUTH_REASON_MIN) {
        $errors['reason'] = 'Explain why in at least ' . AUTH_REASON_MIN . ' characters.';
    } else {
        try {
            $result = db_transaction(static function () use ($txn, $currentUser) {
                $fresh = db_row('SELECT * FROM transactions WHERE id = :id FOR UPDATE', [':id' => $txn['id']]);
                if (in_array($fresh['status'], TXN_LOCKED_STATUSES, true)) {
                    throw new DomainException('This transaction is already ' . $fresh['status'] . '.');
                }

                return transaction_void($fresh, $currentUser);
            });
            log_activity('void', 'transaction', $txn['id'], 'Voided transaction ' . $txn['transaction_number'] . ': ' . $data['reason'], ['status' => $txn['status']], ['status' => 'cancelled']);
            flash('success', $result);
            redirect($back);
        } catch (DomainException $e) {
            $errors['_form'] = $e->getMessage();
        } catch (PDOException $e) {
            $errors['_form'] = db_error_message($e);
        }
    }
}

$pageTitle = 'Delete Transaction';
$activeNav = 'transactions';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb(['Transactions' => url('pages/transactions/index.php'), $txn['transaction_number'] => url($back), 'Delete' => null]) ?>

<section class="page-header">
    <div>
        <h2 class="page-header__title">Delete transaction <?= e($txn['transaction_number']) ?></h2>
        <p class="page-header__subtitle">The transaction is voided, not erased: it is cancelled, leaves the card's used credit, and stays in the history.</p>
    </div>
</section>

<div class="alert alert--warning" role="note"><?= icon('alert') ?><span>Voiding cannot be undone.</span></div>
<?= render_details([
    'Card'     => e($txn['card_nickname'] . ' •••• ' . $txn['last_four']),
    'Date'     => e(format_date($txn['transaction_date'])),
    'Amount'   => '<strong>' . e(format_money($txn['amount'])) . '</strong>',
    'Merchant' => show($txn['merchant_name']),
    'Borrower' => $txn['borrower_id'] ? e(trim($txn['first_name'] . ' ' . $txn['last_name'])) : 'Owner\'s own purchase',
    'Status'   => badge($txn['status']),
]) ?>

<?= render_record_form(url('pages/transactions/void.php', ['id' => $txn['id']]), $fields, $data, $errors, 'Delete transaction', url($back)) ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
