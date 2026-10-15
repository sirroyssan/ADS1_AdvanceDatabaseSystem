<?php
/**
 * Amounts to Repay - pay a card purchase. This is the same "Submit a payment"
 * form as the borrower portal (includes/payment_form.php): how you paid, date,
 * amount, reference, notes, proof, and what the payment is for - with this
 * purchase's amount due this period ticked. The card owner confirms it, and
 * only then is it taken off what is owed. Partial repayments are fine.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once APP_ROOT . '/includes/payment_form.php';

[$currentUser, $layout] = borrowed_require_user();

$id = request_id();
$item = find_or_redirect(is_valid_uuid($id) ? (borrowed_my_purchases($id)[0] ?? null) : null, 'pages/borrowed/index.php', 'Borrowed purchase');
$l = $item['ledger'];
if ($l['outstanding'] - $l['reported'] <= 0.004) {
    flash('info', 'Nothing left to pay on this purchase' . ($l['reported'] > 0 ? ': your repayments are waiting for the card owner\'s confirmation.' : '.'));
    redirect('pages/borrowed/view.php', ['id' => $item['id']]);
}

// The borrower record this purchase is assigned to (the card owner's record of you).
$borrower = db_row('SELECT * FROM borrowers WHERE id = :b', [':b' => $item['borrower_id']]);
if ($l['mode'] === 'obligation') {
    // Older purchases tracked as an obligation are paid through that obligation.
    $groups = payment_obligation_groups($borrower['id'], [$l['obligation']['id']]);
    $preselect = array_column(array_slice($groups[0]['rows'] ?? [], 0, 1), 'key');
} else {
    // This purchase first, then the borrower's other open purchases from the same card owner.
    $groups = payment_purchase_groups($borrower['id']);
    usort($groups, static fn ($a, $b) => ($b['id'] === $item['id']) <=> ($a['id'] === $item['id']));
    $preselect = [];
    foreach ($groups as $g) {
        if ($g['id'] === $item['id']) {
            $preselect = $g['suggest'] ?: array_column(array_slice($g['rows'], 0, 1), 'key');
        }
    }
}

$state = payment_form_handle([
    'borrower' => $borrower, 'user' => $currentUser, 'payment' => null, 'groups' => $groups, 'preselect' => $preselect,
    'csrf_back' => 'pages/borrowed/repay.php?id=' . $item['id'],
]);
if (isset($state['saved'])) {
    if ($state['message']) {
        flash('success', $state['message']);
    }
    redirect('pages/borrowed/view.php', ['id' => $item['id']]);
}

$pageTitle = 'Submit Payment';
$pageScripts = ['assets/js/portal-pay.js'];
$activeNav = 'borrowed';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb(['Amounts to Repay' => url('pages/borrowed/index.php'), $item['transaction_number'] => url('pages/borrowed/view.php', ['id' => $item['id']]), 'Pay' => null]) ?>

<section class="page-header">
    <div>
        <h2 class="page-header__title">Submit a payment</h2>
        <p class="page-header__subtitle">Pay first using one of the owner's accounts, then record it here with the reference number and proof.</p>
    </div>
</section>

<?= render_payment_form($state, url('pages/borrowed/repay.php', ['id' => $item['id']]), url('pages/borrowed/view.php', ['id' => $item['id']])) ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
