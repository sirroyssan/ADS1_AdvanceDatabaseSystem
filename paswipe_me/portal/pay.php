<?php
/**
 * Borrower portal - submit a payment (or fix one the owner sent back).
 *
 * New:      creates borrower_payments (status submitted) + proof file
 *           (documents + payment_attachments) + payment_verification_logs,
 *           and notifies the owner/administrators.
 * ?id=...:  edit the borrower's own payment while it is submitted or needs
 *           correction; a corrected payment goes back to "submitted".
 * Proof of payment is optional for family members (relationship_is_family)
 * and required for everyone else, unless a proof is already attached.
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
$proofRequired = !relationship_is_family($borrower['relationship']);

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
$payGroups = payable_items($bid);
// Current choices (edit) or what was posted, keyed like the form: obligation:schedule.
$chosen = [];
foreach ($payment ? payment_selections($payment['id']) : [] as $sel) {
    $chosen[$sel['obligation_id'] . ':' . ($sel['installment_schedule_id'] ?? '')] = $sel['amount'];
}

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

    // What the borrower says this payment is for (optional; never more than the amount paid).
    $chosen = [];
    foreach ((array) ($_POST['select'] ?? []) as $key => $on) {
        if (is_string($key) && $on === '1') {
            $chosen[$key] = (string) ($_POST['select_amount'][$key] ?? '');
        }
    }
    $selections = [];
    if (!$errors) {
        [$selections, $selectionError] = payment_selections_from_input($_POST, $bid, (float) $data['amount']);
        if ($selectionError !== null) {
            $errors['selection'] = $selectionError;
        }
    }

    $file = $_FILES['proof'] ?? ['error' => UPLOAD_ERR_NO_FILE];
    $hasFile = ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    // Family members (siblings, relatives) may skip the proof; everyone else must attach it.
    if (!$errors && !$hasFile && !$hasProof && $proofRequired) {
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

            db_transaction(function () use ($id, $number, $payment, $data, $stored, $documentId, $currentUser, $bid, $borrower, $selections) {
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
                payment_selections_save($id, $selections);

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
$pageScripts = ['assets/js/portal-pay.js'];
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

        <fieldset class="pay-for" data-pay-for>
            <legend>What is this payment for?</legend>
            <p class="field-help">Tick the items or installments you are paying and the amount for each. Late charges on an item are paid first from what you put on it.
                Anything you do not assign is posted by the owner.</p>
            <?php if (isset($errors['selection'])): ?><p class="field-error"><?= e($errors['selection']) ?></p><?php endif; ?>
            <?php if (!$payGroups): ?>
                <p class="empty-inline">You have nothing open to pay right now.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table table--cards table--compact pay-for__table">
                        <thead><tr><th scope="col" class="col-check"><span class="visually-hidden">Pay</span></th><th scope="col">Item</th><th scope="col">Due</th><th scope="col" class="num">To pay</th><th scope="col" class="num">Amount you are paying</th></tr></thead>
                        <?php foreach ($payGroups as $g): ?>
                            <tbody>
                            <tr class="pay-for__group"><td colspan="5"><strong><?= e($g['obligation']['obligation_number']) ?></strong>
                                <?= $g['obligation']['description'] ? ' · ' . e(excerpt($g['obligation']['description'], 60)) : '' ?>
                                <span class="text-muted"> · total to pay <?= e(format_money($g['owed'])) ?><?= $g['penalties'] > 0 ? ' (incl. ' . e(format_money($g['penalties'])) . ' late charges)' : '' ?></span></td></tr>
                            <?php if ($g['months']): ?>
                                <tr class="pay-for__months"><td colspan="5"><span class="text-muted">Monthly amounts (paid oldest month first):</span>
                                    <?php foreach (array_slice($g['months'], 0, 4) as $m): ?>
                                        <span class="pay-for__month">#<?= e($m['installment_number']) ?> · <?= e(format_date($m['due_date'])) ?> · <?= e(format_money($m['remaining_amount'])) ?><?= $m['status'] === 'overdue' ? ' <span class="status status--danger">Overdue</span>' : '' ?></span>
                                    <?php endforeach; ?>
                                    <?= count($g['months']) > 4 ? '<span class="text-muted">+' . e(count($g['months']) - 4) . ' more</span>' : '' ?>
                                    <a href="<?= e(url('portal/obligation.php', ['id' => $g['obligation']['id']])) ?>">See schedule</a></td></tr>
                            <?php endif; ?>
                            <?php foreach ($g['rows'] as $r): ?>
                                <?php $on = array_key_exists($r['key'], $chosen); ?>
                                <tr>
                                    <td class="col-check"><input type="checkbox" name="select[<?= e($r['key']) ?>]" value="1" data-pay-check <?= $on ? 'checked' : '' ?>
                                               id="sel-<?= e(md5($r['key'])) ?>"></td>
                                    <td data-label="Item"><label for="sel-<?= e(md5($r['key'])) ?>"><?= e($r['label']) ?></label>
                                        <?= $r['overdue'] ? ' <span class="status status--danger">Overdue</span>' : '' ?></td>
                                    <td data-label="Due"><?= $r['due_date'] ? e(format_date($r['due_date'])) : '<span class="text-muted">—</span>' ?></td>
                                    <td data-label="To pay" class="num"><?= e(format_money($r['owed'])) ?></td>
                                    <td data-label="Amount you are paying" class="num">
                                        <input type="text" inputmode="decimal" class="input-num" name="select_amount[<?= e($r['key']) ?>]" data-pay-amount data-owed="<?= e(number_format($r['owed'], 2, '.', '')) ?>"
                                               value="<?= e($on ? $chosen[$r['key']] : '') ?>" <?= $on ? '' : 'disabled' ?> aria-label="Amount for <?= e($g['obligation']['obligation_number'] . ' ' . $r['label']) ?>">
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        <?php endforeach; ?>
                    </table>
                </div>
                <p class="pay-for__summary" aria-live="polite">Assigned <strong data-pay-assigned>₱0.00</strong> of <strong data-pay-total>₱0.00</strong> paid ·
                    not assigned <strong data-pay-rest>₱0.00</strong> <span class="text-muted">(the owner will post it)</span></p>
            <?php endif; ?>
        </fieldset>

        <div class="form-grid">
            <div class="form-group form-group--full">
                <label for="f_proof">Proof of payment<?= $hasProof || !$proofRequired ? ' <span class="text-muted">(optional)</span>' : ' <span class="required" aria-hidden="true">*</span>' ?></label>
                <input type="file" id="f_proof" name="proof" accept=".jpg,.jpeg,.png,.gif,.webp,.pdf"<?= $proofRequired && !$hasProof ? ' required' : '' ?>
                       class="<?= isset($errors['proof']) ? 'is-invalid' : '' ?>" aria-describedby="f_proof-help f_proof-error">
                <p class="field-help" id="f_proof-help">Screenshot, photo or PDF of the receipt, up to <?= e(DOCUMENT_MAX_BYTES / 1024 / 1024) ?> MB.
                    <?= $hasProof ? 'A proof is already attached; add another only if needed.'
                        : ($proofRequired ? 'Required for your account.' : 'Optional for family members, but it helps the owner approve your payment faster.') ?></p>
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
