<?php
/**
 * Borrower portal - submit a payment (or fix one the owner sent back).
 *
 * New:      creates borrower_payments (status submitted) + proof file
 *           (documents + payment_attachments) + payment_verification_logs,
 *           and notifies the owner/administrators.
 * ?id=...:  edit the borrower's own payment while it is submitted or needs
 *           correction; a corrected payment goes back to "submitted".
 * Proof of payment is required unless the method is cash (or a proof is
 * already attached).
 */

require_once __DIR__ . '/../includes/auth_check.php';
require_once APP_ROOT . '/pages/borrower_payments/payment_functions.php';
require_once APP_ROOT . '/pages/documents/document_functions.php';

[$currentUser, $borrower] = require_borrower();
$bid = $borrower['id'];

$payment = null;
$editId = input_string($_GET, 'id') ?: input_string($_POST, 'id');
if ($editId !== '') {
    $payment = portal_find('SELECT * FROM borrower_payments WHERE id = :id AND borrower_id = :b', $editId, $bid, 'portal/payments.php', 'Payment');
    if (!in_array($payment['status'], PAYMENT_EDITABLE_STATUSES, true)) {
        flash('error', 'A payment that is ' . humanize($payment['status']) . ' can no longer be changed.');
        redirect('portal/payment.php', ['id' => $payment['id']]);
    }
}
$hasProof = $payment && (int) db_value('SELECT COUNT(*) FROM payment_attachments WHERE borrower_payment_id = :p', [':p' => $payment['id']]) > 0;

$methods = portal_payment_method_options($bid, $payment['payment_method_id'] ?? null);
$fields = [
    'payment_method_id' => ['label' => 'How did you pay?', 'type' => 'select', 'options' => $methods, 'required' => true, 'empty_label' => 'Select payment method',
                            'help' => $methods ? 'Choose the owner\'s account you sent the money to, or your own account you paid from.' : 'No payment methods are set up yet. Ask the owner to add one.'],
    'payment_date'      => ['label' => 'Payment date', 'type' => 'date', 'required' => true, 'initial' => date('Y-m-d')],
    'amount'            => ['label' => 'Amount paid', 'type' => 'decimal', 'required' => true, 'min_exclusive' => 0],
    'reference_number'  => ['label' => 'Reference number', 'type' => 'text', 'max' => 150, 'help' => 'e.g. the GCash / bank transfer reference no. Leave blank for cash.'],
    'notes'             => ['label' => 'Notes for the owner', 'type' => 'textarea', 'rows' => 3, 'help' => 'Optional, e.g. which item or month this payment is for.'],
];
$data = $payment ? form_data_from_row($fields, $payment) : form_defaults($fields);
$errors = [];

if (is_post()) {
    require_csrf($payment ? 'portal/pay.php?id=' . $payment['id'] : 'portal/pay.php');
    [$data, $errors] = validate_fields($fields, $_POST);

    if (!$errors && $data['payment_date'] > date('Y-m-d')) {
        $errors['payment_date'] = 'Payment date cannot be in the future.';
    }
    if (!$errors && !array_key_exists($data['payment_method_id'], $methods)) {
        $errors['payment_method_id'] = 'Choose one of the listed payment methods.';
    }
    if (!$errors && $data['reference_number'] !== null && (int) db_value(
        "SELECT COUNT(*) FROM borrower_payments WHERE borrower_id = :b AND reference_number = :r AND status NOT IN ('rejected', 'cancelled') AND id <> :id",
        [':b' => $bid, ':r' => $data['reference_number'], ':id' => $payment['id'] ?? '']
    ) > 0) {
        $errors['reference_number'] = 'You already submitted a payment with this reference number.';
    }

    $file = $_FILES['proof'] ?? ['error' => UPLOAD_ERR_NO_FILE];
    $hasFile = ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    $isCash = !$errors && db_value('SELECT method_type FROM payment_methods WHERE id = :id', [':id' => $data['payment_method_id']]) === 'cash';
    if (!$errors && !$hasFile && !$hasProof && !$isCash) {
        $errors['proof'] = 'Attach a screenshot or photo of the receipt so the owner can verify the payment.';
    }

    $documentId = new_uuid();
    $stored = null;
    if (!$errors && $hasFile) {
        [$stored, $uploadError] = document_store_upload($file, $documentId);
        if ($uploadError !== null) {
            $errors['proof'] = $uploadError;
        }
    }

    if (!$errors) {
        try {
            $id = $payment['id'] ?? new_uuid();
            $number = $payment['payment_number'] ?? generate_reference('PAY', 'borrower_payments', 'payment_number');

            db_transaction(function () use ($id, $number, $payment, $data, $stored, $documentId, $currentUser, $bid, $borrower) {
                if ($payment === null) {
                    db_insert('borrower_payments', ['id' => $id, 'payment_number' => $number, 'borrower_id' => $bid] + $data
                        + ['status' => 'submitted', 'allocation_status' => 'unallocated', 'submitted_at' => date('Y-m-d H:i:s')]);
                    payment_log(['id' => $id, 'status' => null], 'submitted', 'submitted', 'Submitted by the borrower in the portal', $currentUser['id']);
                } else {
                    $update = $data;
                    if ($payment['status'] === 'correction_required') {
                        $update['status'] = 'submitted';
                        payment_log($payment, 'submitted', 'corrected', 'Corrected by the borrower in the portal', $currentUser['id']);
                    }
                    db_update('borrower_payments', $update, $id);
                }

                if ($stored) {
                    db_insert('documents', ['id' => $documentId, 'document_type' => 'payment_proof'] + $stored + [
                        'uploaded_by' => $currentUser['id'],
                        'metadata'    => json_encode(['description' => 'Proof for payment ' . $number, 'source' => 'borrower_portal'], JSON_UNESCAPED_UNICODE),
                    ]);
                    db_insert('payment_attachments', [
                        'id' => new_uuid(), 'borrower_payment_id' => $id, 'document_id' => $documentId, 'attachment_type' => 'payment_proof',
                    ]);
                }

                $who = borrower_name($borrower);
                notify_admins(
                    'payment_submitted',
                    ($payment ? 'Payment corrected: ' : 'New payment: ') . $number,
                    $who . ($payment ? ' updated payment ' : ' submitted payment ') . $number . ' of ' . format_money($data['amount'])
                        . ' (paid ' . format_date($data['payment_date']) . '). Please review it.',
                    'borrower_payment',
                    $id
                );
            });

            log_activity($payment ? 'update' : 'create', 'borrower_payment', $id,
                ($payment ? 'Borrower updated payment ' : 'Borrower submitted payment ') . $number . ' of ' . format_money($data['amount']), $payment ? array_intersect_key($payment, $data) : null, $data);
            flash('success', $payment
                ? 'Payment ' . $number . ' was updated and sent back to the owner for review.'
                : 'Payment ' . $number . ' was submitted. The owner will review it; it counts toward your balance once approved.');
            redirect('portal/payment.php', ['id' => $id]);
        } catch (PDOException $e) {
            if ($stored) {
                @unlink(APP_ROOT . '/' . DOCUMENT_STORAGE_DIR . '/' . $stored['storage_key']);
            }
            $errors['_form'] = db_error_message($e);
        }
    } elseif ($stored) {
        @unlink(APP_ROOT . '/' . DOCUMENT_STORAGE_DIR . '/' . $stored['storage_key']);
    }
}

$receiving = db_all(
    'SELECT display_name, method_type, account_name, masked_account_number FROM payment_methods
      WHERE borrower_id IS NULL AND owner_user_id IS NOT NULL AND is_active = 1 ORDER BY display_name'
);

$pageTitle = $payment ? 'Fix Payment' : 'Submit Payment';
$activeNav = $payment ? 'portal_payments' : 'portal_pay';
$layout = 'portal';
require APP_ROOT . '/includes/header.php';
$action = $payment ? url('portal/pay.php', ['id' => $payment['id']]) : url('portal/pay.php');
?>

<?= render_breadcrumb($payment
    ? ['My Payments' => url('portal/payments.php'), $payment['payment_number'] => url('portal/payment.php', ['id' => $payment['id']]), 'Fix' => null]
    : ['Submit Payment' => null]) ?>

<section class="page-header">
    <div>
        <h2 class="page-header__title"><?= $payment ? 'Fix payment ' . e($payment['payment_number']) : 'Submit a payment' ?></h2>
        <p class="page-header__subtitle">Pay first using one of the owner's accounts, then record it here with the reference number and proof.</p>
    </div>
</section>

<?php if ($payment && $payment['status'] === 'correction_required' && $payment['correction_reason']): ?>
    <div class="alert alert--warning" role="status"><?= icon('alert') ?>
        <span><strong>What to fix:</strong> <?= e($payment['correction_reason']) ?></span></div>
<?php endif; ?>

<div class="detail-layout">
    <form method="post" action="<?= e($action) ?>" enctype="multipart/form-data" class="form-card form-card--wide" novalidate data-validate>
        <?= csrf_field() ?>
        <input type="hidden" name="MAX_FILE_SIZE" value="<?= e(DOCUMENT_MAX_BYTES) ?>">
        <?php if ($errors): ?>
            <div class="alert alert--error" role="alert"><?= icon('alert') ?><span><?= e($errors['_form'] ?? 'Please correct the highlighted fields below.') ?></span></div>
        <?php endif; ?>
        <?= render_fields($fields, $data, $errors) ?>
        <div class="form-grid">
            <div class="form-group form-group--full">
                <label for="f_proof">Proof of payment<?= $hasProof ? '' : ' <span class="required" aria-hidden="true">*</span>' ?></label>
                <input type="file" id="f_proof" name="proof" accept=".jpg,.jpeg,.png,.gif,.webp,.pdf"
                       class="<?= isset($errors['proof']) ? 'is-invalid' : '' ?>" aria-describedby="f_proof-help f_proof-error">
                <p class="field-help" id="f_proof-help">Screenshot, photo or PDF of the receipt, up to <?= e(DOCUMENT_MAX_BYTES / 1024 / 1024) ?> MB.
                    <?= $hasProof ? 'A proof is already attached; add another only if needed.' : 'Not needed for cash payments.' ?></p>
                <p class="field-error" id="f_proof-error" <?= isset($errors['proof']) ? '' : 'hidden' ?>><?= e($errors['proof'] ?? '') ?></p>
            </div>
        </div>
        <div class="form-actions">
            <a href="<?= e($payment ? url('portal/payment.php', ['id' => $payment['id']]) : url('portal/index.php')) ?>" class="btn btn--outline">Cancel</a>
            <button type="submit" class="btn btn--primary" data-loading-text="Sending…"><?= icon('check') ?> <span class="btn__label"><?= $payment ? 'Save and resubmit' : 'Submit payment' ?></span></button>
        </div>
    </form>

    <div class="detail-side">
        <section class="panel">
            <header class="panel__header"><h3>Where to pay</h3></header>
            <?php if (!$receiving): ?>
                <p class="empty-inline">Ask the owner where to send your payment.</p>
            <?php else: ?>
                <ul class="child-list">
                    <?php foreach ($receiving as $m): ?>
                        <li><span><strong><?= e($m['display_name']) ?></strong><?= $m['account_name'] ? '<br><span class="text-muted">' . e($m['account_name']) . '</span>' : '' ?></span>
                            <span><?= e(humanize($m['method_type'])) ?><?= $m['masked_account_number'] ? ' · ' . e($m['masked_account_number']) : '' ?></span></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
        <section class="panel">
            <header class="panel__header"><h3>What happens next</h3></header>
            <ol class="steps-list">
                <li>The owner checks the amount, reference and proof.</li>
                <li>Approved payments are applied to what you owe.</li>
                <li>If something is wrong, you will be asked to fix it here.</li>
            </ol>
        </section>
    </div>
</div>

<?php require APP_ROOT . '/includes/footer.php'; ?>
