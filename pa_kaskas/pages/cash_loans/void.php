<?php
/**
 * Cash Loans - void a loan entered by mistake (opened in a modal). The reason
 * is required. The loan, its schedule and its borrowers' obligations become
 * cancelled and stop counting everywhere; nothing is erased (cash_loan_void()).
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/cash_loan_functions.php';

$currentUser = require_admin();

$loan = find_or_redirect(cash_loan_find(request_id()), 'pages/cash_loans/index.php', 'Cash loan');
$back = 'pages/cash_loans/view.php?id=' . $loan['id'];
$blockers = cash_loan_void_blockers($loan);
$obligations = db_all(
    "SELECT o.obligation_number, o.total_amount_due, b.first_name, b.last_name FROM borrower_obligations o
       JOIN cash_loan_allocations a ON a.id = o.cash_loan_allocation_id JOIN borrowers b ON b.id = o.borrower_id
      WHERE a.cash_loan_id = :l AND o.status <> 'cancelled'",
    [':l' => $loan['id']]
);
$error = null;
$reason = '';

if (is_post()) {
    require_csrf($back);
    $reason = trim(mb_substr(input_string($_POST, 'reason'), 0, 1000));
    if (mb_strlen($reason) < 5) {
        $error = 'Enter the reason for voiding this cash loan (at least 5 characters).';
    } else {
        try {
            $error = cash_loan_void($loan, $reason, $currentUser);
        } catch (DomainException $e) {
            $error = $e->getMessage();
        } catch (PDOException $e) {
            $error = db_error_message($e);
        }
        if ($error === null) {
            flash('success', 'Cash loan ' . $loan['loan_reference'] . ' was voided' . ($obligations ? ' with ' . count($obligations) . ' borrower obligation(s)' : '')
                . '. It no longer counts on the card, its statements or any balance; the records are kept as cancelled.');
            redirect($back);
        }
    }
}

$pageTitle = 'Void cash loan';
$activeNav = 'cash_loans';
require APP_ROOT . '/includes/header.php';
?>

<section class="page-header">
    <div>
        <h2 class="page-header__title">Void cash loan <?= e($loan['loan_reference']) ?></h2>
        <p class="page-header__subtitle"><?= e(format_money($loan['approved_amount'])) ?> · <?= e($loan['card_nickname'] ?? '') ?> · started <?= e(format_date($loan['start_date'])) ?></p>
    </div>
</section>

<?php if ($blockers): ?>
    <div class="alert alert--error" role="alert"><?= icon('alert') ?><span>This loan cannot be voided while money is attached: <?= e(implode(' ', $blockers)) ?> Reject or void those payments first.</span></div>
    <div class="form-actions"><a href="<?= e(url($back)) ?>" class="btn btn--outline">Close</a></div>
<?php else: ?>
<form method="post" action="<?= e(url('pages/cash_loans/void.php', ['id' => $loan['id']])) ?>" class="form-card" novalidate data-validate>
    <?= csrf_field() ?>
    <?php if ($error !== null): ?><div class="alert alert--error" role="alert"><?= icon('alert') ?><span><?= e($error) ?></span></div><?php endif; ?>
    <p>Use this for a loan recorded by mistake (for example, entered twice). This cancels:</p>
    <ul>
        <li>the loan and its repayment schedule: it stops counting on the card's credit and statements;</li>
        <?php foreach ($obligations as $o): ?>
            <li>obligation <strong><?= e($o['obligation_number']) ?></strong> of <?= e(trim($o['first_name'] . ' ' . $o['last_name'])) ?> (<?= e(format_money($o['total_amount_due'])) ?>): the borrower is told they owe nothing on it.</li>
        <?php endforeach; ?>
    </ul>
    <div class="form-group">
        <label for="f_void_reason">Reason for voiding <span class="required" aria-hidden="true">*</span></label>
        <textarea id="f_void_reason" name="reason" rows="3" maxlength="1000" required minlength="5" autofocus
                  placeholder="e.g. Entered twice; the real loan is CL-20261011-0002"><?= e($reason) ?></textarea>
        <p class="field-help">Nothing is erased: the records stay as Cancelled with this reason, and the activity log keeps the old values.</p>
    </div>
    <div class="form-actions">
        <a href="<?= e(url($back)) ?>" class="btn btn--outline">Cancel</a>
        <button type="submit" class="btn btn--danger" data-loading-text="Voiding…"><?= icon('trash') ?> <span class="btn__label">Void cash loan</span></button>
    </div>
</form>
<?php endif; ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
