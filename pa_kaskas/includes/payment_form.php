<?php
/**
 * One "Submit a payment" form for every repayment a borrower makes - the same
 * fields, layout, proof rules and "What is this payment for?" table whether it
 * is opened from My Account (portal/pay.php) or from a card purchase
 * (pages/borrowed/repay.php).
 *
 * The table lists the borrower's open items: obligations (each installment or
 * the item as a whole) and card purchases they made on the owner's card (each
 * open installment, or the purchase). On save:
 *   - the amounts put on a card purchase become a repayment of that purchase
 *     (borrower_payments.transaction_id; the owner confirms it);
 *   - the rest is one payment with the obligation choices
 *     (borrower_payment_selections), applied when the owner approves it.
 * All of it is saved in one database transaction; the proof file is attached
 * to every payment it covers.
 */

defined('APP_ROOT') or exit('No direct access.');

require_once APP_ROOT . '/pages/borrower_payments/payment_functions.php';
require_once APP_ROOT . '/pages/documents/document_functions.php';

function payment_form_fields(array $methods): array
{
    return [
        // Required whenever there is something to choose; without any, the payment can still be recorded.
        'payment_method_id' => ['label' => 'How did you pay?', 'type' => 'select', 'options' => $methods, 'required' => (bool) $methods,
                                'empty_label' => $methods ? 'Select payment method' : '— No payment methods set up —',
                                'help' => $methods ? 'Choose the owner\'s account you sent the money to, or your own account you paid from.' : 'The owner has not added payment methods yet; say how you paid in the notes.'],
        'payment_date'      => ['label' => 'Payment date', 'type' => 'date', 'required' => true, 'initial' => date('Y-m-d')],
        'amount'            => ['label' => 'Amount paid', 'type' => 'decimal', 'required' => true, 'min_exclusive' => 0],
        'reference_number'  => ['label' => 'Reference number', 'type' => 'text', 'max' => 150, 'help' => 'e.g. the GCash / bank transfer reference no. Leave blank for cash.'],
        'notes'             => ['label' => 'Notes for the owner', 'type' => 'textarea', 'rows' => 3, 'help' => 'Optional, e.g. which item or month this payment is for.'],
    ];
}

/**
 * Open card purchases of a borrower record as payable groups: one row per open
 * installment (or the purchase), plus late charges. $extra adds back what a
 * payment being edited already counts as reported.
 */
function payment_purchase_groups(string $borrowerId, ?string $onlyTransactionId = null, float $extra = 0.0): array
{
    $sql = borrowed_select_sql() . " WHERE t.borrower_id = :b AND t.status NOT IN ('cancelled', 'refunded')";
    $params = [':b' => $borrowerId];
    if ($onlyTransactionId !== null) {
        $sql .= ' AND t.id = :t';
        $params[':t'] = $onlyTransactionId;
    }
    $groups = [];
    foreach (borrowed_with_ledgers(db_all($sql . ' ORDER BY t.transaction_date, t.created_at', $params)) as $p) {
        $l = $p['ledger'];
        $open = round($l['outstanding'] - $l['reported'] + $extra, 2);
        if ($l['mode'] !== 'transaction' || $open <= 0.004) {
            continue;
        }
        $id = $p['id'];
        $rows = [];
        $suggest = [];
        if ($l['penalty_unpaid'] > 0.004) {
            $rows[] = ['key' => "txn:$id:pen", 'label' => 'Late charges', 'due_date' => null, 'owed' => min($open, $l['penalty_unpaid']), 'overdue' => true,
                       'part' => 'penalty', 'schedule_id' => null];
            $suggest[] = "txn:$id:pen";
        }
        foreach ($l['rows'] as $i => $r) {
            if ($r['remaining'] <= 0.004) {
                continue;
            }
            $key = "txn:$id:$i";
            $rows[] = ['key' => $key, 'label' => $l['installments'] > 0 ? 'Installment ' . ($i + 1) . ' of ' . $l['installments'] : 'This purchase',
                       'due_date' => $r['due']['date'], 'owed' => min($open, $r['remaining']), 'overdue' => $r['overdue'],
                       'part' => $r['schedule_id'] ? 'installment' : 'purchase', 'schedule_id' => $r['schedule_id']];
            if ($r['overdue'] || ($l['current'] !== null && $l['current']['n'] === $i + 1)) {
                $suggest[] = $key;   // due this period
            }
        }
        if ($l['terms_missing']) {
            $rows[] = ['key' => "txn:$id:all", 'label' => 'This purchase (installment terms not set yet)', 'due_date' => null, 'owed' => $open, 'overdue' => false,
                       'part' => null, 'schedule_id' => null];
        }
        $groups[] = [
            'kind' => 'purchase', 'id' => $id, 'title' => $p['merchant_name'] ?: ($p['notes'] ? excerpt($p['notes'], 40) : $p['transaction_number']),
            'subtitle' => 'Card purchase ' . ($p['merchant_name'] || $p['notes'] ? $p['transaction_number'] . ' · ' : '') . format_date($p['transaction_date']) . ' · ' . $p['issuer'] . ' •••• ' . $p['last_four']
                . ($l['reported'] - $extra > 0.004 ? ' · ' . format_money($l['reported'] - $extra) . ' reported, waiting for confirmation' : ''),
            'owed' => $open, 'penalties' => $l['penalty_unpaid'], 'rows' => $rows, 'months' => [], 'suggest' => $suggest,
            'link' => url('pages/borrowed/view.php', ['id' => $id]), 'purchase' => $p,
        ];
    }

    return $groups;
}

/** Obligations of a borrower (payable_items) in the same group shape. */
function payment_obligation_groups(string $borrowerId, ?array $onlyIds = null): array
{
    $groups = [];
    foreach (payable_items($borrowerId) as $g) {
        if ($onlyIds !== null && !in_array($g['obligation']['id'], $onlyIds, true)) {
            continue;
        }
        $groups[] = [
            'kind' => 'obligation', 'id' => $g['obligation']['id'], 'title' => $g['obligation']['obligation_number'],
            'subtitle' => $g['obligation']['description'] ? excerpt($g['obligation']['description'], 60) : '',
            'owed' => $g['owed'], 'penalties' => $g['penalties'], 'rows' => $g['rows'], 'months' => $g['months'], 'suggest' => [],
            'link' => url('portal/obligation.php', ['id' => $g['obligation']['id']]),
        ];
    }

    return $groups;
}

/**
 * Card purchase choices from the form: [[transaction id => amount], error|null].
 */
function payment_purchase_choices(array $input, array $groups): array
{
    $byPurchase = [];
    $parts = [];   // what each purchase repayment is for, as the borrower chose it
    foreach ($groups as $g) {
        if ($g['kind'] !== 'purchase') {
            continue;
        }
        $sum = 0.0;
        foreach ($g['rows'] as $r) {
            if (($input['select'][$r['key']] ?? '') !== '1') {
                continue;
            }
            $raw = str_replace([',', ' ', '₱'], '', (string) ($input['select_amount'][$r['key']] ?? ''));
            if (!preg_match('/^\d{1,13}(\.\d{1,2})?$/', $raw) || (float) $raw <= 0) {
                return [[], 'Enter an amount for ' . $g['title'] . ' — ' . $r['label'] . '.'];
            }
            if ((float) $raw > $r['owed'] + 0.004) {
                return [[], $g['title'] . ' — ' . $r['label'] . ' only has ' . format_money($r['owed']) . ' to pay.'];
            }
            $sum += round((float) $raw, 2);
            if (!empty($r['part'])) {
                $parts[$g['id']][] = ['part' => $r['part'], 'installment_schedule_id' => $r['schedule_id'] ?? null, 'amount' => round((float) $raw, 2)];
            }
        }
        if ($sum > $g['owed'] + 0.004) {
            return [[], 'Only ' . format_money($g['owed']) . ' is left to pay on ' . $g['title'] . '.'];
        }
        if ($sum > 0) {
            $byPurchase[$g['id']] = round($sum, 2);
        }
    }

    return [$byPurchase, null, $parts];
}

/**
 * Handle the form. $ctx:
 *   borrower       borrower record the payment is from (methods, proof rule, items)
 *   user           signed-in user
 *   payment        payment being edited (portal "Fix"), or null
 *   groups         payable groups (payment_purchase_groups / payment_obligation_groups)
 *   preselect      group keys ticked when the form opens (amount = what is due this period)
 *   csrf_back      path for require_csrf
 * Returns the view state; on success returns ['saved' => [payment ids], 'message' => ...].
 */
function payment_form_handle(array $ctx): array
{
    $borrower = $ctx['borrower'];
    $payment = $ctx['payment'] ?? null;
    $groups = $ctx['groups'];
    $methods = portal_payment_method_options($borrower['id'], $payment['payment_method_id'] ?? null);
    $fields = payment_form_fields($methods);
    $hasProof = $payment && (int) db_value('SELECT COUNT(*) FROM payment_attachments WHERE borrower_payment_id = :p', [':p' => $payment['id']]) > 0;
    $proofRequired = !relationship_is_family($borrower['relationship']);
    $hasObligations = (bool) array_filter($groups, static fn ($g) => $g['kind'] === 'obligation');

    // Ticked when the form opens: the payment's own choices (edit) or what is due this period.
    $chosen = [];
    if ($payment && $payment['transaction_id']) {
        $left = (float) $payment['amount'];
        foreach ($groups[0]['rows'] ?? [] as $r) {
            if ($left <= 0.004) {
                break;
            }
            $chosen[$r['key']] = number_format(min($left, $r['owed']), 2, '.', '');
            $left -= min($left, $r['owed']);
        }
    } elseif ($payment) {
        $rowsOf = [];
        foreach ($groups as $g) {
            $rowsOf[$g['id']] = $g['rows'];
        }
        foreach (payment_selections($payment['id']) as $sel) {
            if ($sel['installment_schedule_id'] !== null) {
                $chosen[$sel['obligation_id'] . ':' . $sel['installment_schedule_id']] = $sel['amount'];
                continue;
            }
            // An amount on the obligation as a whole ticks its rows (late charges, then months) oldest first.
            $left = (float) $sel['amount'];
            foreach ($rowsOf[$sel['obligation_id']] ?? [] as $r) {
                if ($left <= 0.004 || !str_starts_with($r['key'], $sel['obligation_id'] . ':') || preg_match('/:[0-9a-f-]{36}$/', $r['key'])) {
                    continue;
                }
                $take = min($left, $r['owed']);
                $chosen[$r['key']] = number_format($take, 2, '.', '');
                $left -= $take;
            }
        }
    } else {
        foreach ($groups as $g) {
            foreach ($g['rows'] as $r) {
                if (in_array($r['key'], $ctx['preselect'] ?? [], true)) {
                    $chosen[$r['key']] = number_format($r['owed'], 2, '.', '');
                }
            }
        }
    }
    $data = $payment ? form_data_from_row($fields, $payment) : form_defaults($fields, $chosen ? ['amount' => number_format(array_sum(array_map('floatval', $chosen)), 2, '.', '')] : []);
    $errors = [];
    $state = compact('fields', 'data', 'errors', 'groups', 'chosen', 'hasProof', 'proofRequired', 'payment');

    if (!is_post()) {
        return $state;
    }
    require_csrf($ctx['csrf_back']);
    [$data, $errors] = validate_fields($fields, $_POST);
    if (!$errors && $data['payment_date'] > date('Y-m-d')) {
        $errors['payment_date'] = 'Payment date cannot be in the future.';
    }
    if (!$errors && $data['payment_method_id'] !== null && !array_key_exists($data['payment_method_id'], $methods)) {
        $errors['payment_method_id'] = 'Choose one of the listed payment methods.';
    }
    if (!$errors && $data['reference_number'] !== null && (int) db_value(
        "SELECT COUNT(*) FROM borrower_payments WHERE borrower_id = :b AND reference_number = :r AND status NOT IN ('rejected', 'cancelled') AND id <> :id",
        [':b' => $borrower['id'], ':r' => $data['reference_number'], ':id' => $payment['id'] ?? '']
    ) > 0) {
        $errors['reference_number'] = 'You already submitted a payment with this reference number.';
    }

    $chosen = [];
    foreach ((array) ($_POST['select'] ?? []) as $key => $on) {
        if (is_string($key) && $on === '1') {
            // Typed with thousands separators (5,416.67): keep the plain number so (float) and format_money() read it right.
            $chosen[$key] = str_replace([',', ' ', '₱'], '', (string) ($_POST['select_amount'][$key] ?? ''));
        }
    }
    $purchases = [];
    $purchaseParts = [];
    $selections = [];
    if (!$errors) {
        [$purchases, $error, $purchaseParts] = payment_purchase_choices($_POST, $groups) + [2 => []];
        if ($error === null && $hasObligations) {
            [$selections, $error] = payment_selections_from_input($_POST, $borrower['id'], (float) $data['amount']);
        }
        $assigned = array_sum($purchases) + array_sum(array_column($selections, 'amount'));
        $rest = round((float) $data['amount'] - $assigned, 2);
        if ($error === null && $rest < -0.004) {
            $error = 'You assigned ' . format_money($assigned) . ', which is more than the ' . format_money($data['amount']) . ' you paid.';
        }
        // Money not put on an item can only be posted by the owner to an obligation.
        if ($error === null && $rest > 0.004 && (!$hasObligations || ($payment && $payment['transaction_id']))) {
            $error = 'Put the whole ' . format_money($data['amount']) . ' on the items you are paying (' . format_money($rest) . ' is not assigned yet).';
        }
        if ($error !== null) {
            $errors['selection'] = $error;
        }
    }

    $file = $_FILES['proof'] ?? ['error' => UPLOAD_ERR_NO_FILE];
    $hasFile = ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    // Family members (siblings, relatives) may skip the proof; everyone else must attach it.
    if (!$errors && !$hasFile && !$hasProof && $proofRequired) {
        $errors['proof'] = 'Attach a screenshot or photo of the receipt so the owner can verify the payment.';
    }
    // The same form sent twice (double click, refresh) is saved once.
    if (!$errors && !$payment && !form_once_use('payment_form', input_string($_POST, 'once'))) {
        flash('info', 'This payment was already submitted.');
        return ['saved' => [], 'message' => null] + $state;
    }
    $documentId = new_uuid();
    $stored = null;
    if (!$errors && $hasFile) {
        // Straight to the owner's Google Drive when connected, else kept on this computer.
        [$stored, $uploadError] = document_store_upload($file, $documentId, $borrower['owner_user_id'],
            date('Y-m-d') . ' ' . trim(($borrower['first_name'] ?? '') . ' ' . ($borrower['last_name'] ?? '')) . ' proof');
        if ($uploadError !== null) {
            $errors['proof'] = $uploadError;
        }
    }
    if ($errors) {
        document_discard_stored($stored, $borrower['owner_user_id']);
        return compact('fields', 'data', 'errors', 'groups', 'chosen', 'hasProof', 'proofRequired', 'payment');
    }

    $step = 'payment';
    try {
        $saved = db_transaction(static function () use ($ctx, $borrower, $payment, $data, $purchases, $purchaseParts, $selections, $rest, $stored, $documentId, &$step) {
            $user = $ctx['user'];
            $ids = [];
            $common = array_intersect_key($data, array_flip(['payment_method_id', 'payment_date', 'reference_number', 'notes']));
            if ($payment) {
                // Edit (portal "Fix"): the payment keeps its kind; a corrected one goes back to "submitted".
                $update = $data;
                if ($payment['status'] === 'correction_required') {
                    $update['status'] = 'submitted';
                    payment_log($payment, 'submitted', 'corrected', 'Corrected by the borrower', $user['id']);
                }
                db_update('borrower_payments', $update, $payment['id']);
                if (!$payment['transaction_id']) {
                    payment_selections_save($payment['id'], $selections);
                } else {
                    borrowed_parts_save($payment['id'], $payment['transaction_id'], $purchaseParts[$payment['transaction_id']] ?? []);
                }
                $ids[] = $payment['id'];
            } else {
                // Each card purchase gets its own repayment, confirmed by the card owner.
                foreach ($purchases as $txnId => $amount) {
                    $p = db_row(borrowed_select_sql() . ' WHERE t.id = :t', [':t' => $txnId]);
                    $ids[] = borrowed_record_repayment($p, ['amount' => number_format($amount, 2, '.', '')] + $common, $user['id'], false, $purchaseParts[$txnId] ?? []);
                }
                // The rest: one payment with the obligation choices (also any amount the owner posts).
                if ($selections || $rest > 0.004) {
                    $id = new_uuid();
                    $number = generate_reference('PAY', 'borrower_payments', 'payment_number');
                    $amount = round(array_sum(array_column($selections, 'amount')) + max(0, $rest), 2);
                    db_insert('borrower_payments', ['id' => $id, 'payment_number' => $number, 'borrower_id' => $borrower['id'], 'amount' => number_format($amount, 2, '.', '')] + $common
                        + ['status' => 'submitted', 'allocation_status' => 'unallocated', 'submitted_at' => date('Y-m-d H:i:s')]);
                    payment_log(['id' => $id, 'status' => null], 'submitted', 'submitted', 'Submitted by the borrower', $user['id']);
                    payment_selections_save($id, $selections);
                    notify_lender($borrower['id'], 'payment_submitted', 'New payment: ' . $number,
                        borrower_name($borrower) . ' submitted payment ' . $number . ' of ' . format_money($amount) . ' (paid ' . format_date($data['payment_date']) . '). Please review it.',
                        'borrower_payment', $id);
                    $ids[] = $id;
                }
            }
            if ($stored) {
                $step = 'proof';
                [$columns, $driveMeta] = document_stored_columns($stored);
                db_insert('documents', ['id' => $documentId, 'document_type' => 'payment_proof'] + $columns + [
                    'owner_user_id' => $borrower['owner_user_id'],   // the lender who reviews it
                    'uploaded_by' => $user['id'],
                    'metadata'    => json_encode(['description' => 'Proof of payment', 'source' => 'borrower'] + $driveMeta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]);
                foreach ($ids as $pid) {
                    db_insert('payment_attachments', ['id' => new_uuid(), 'borrower_payment_id' => $pid, 'document_id' => $documentId, 'attachment_type' => 'payment_proof']);
                }
            }

            return $ids;
        });
    } catch (PDOException $e) {
        document_discard_stored($stored, $borrower['owner_user_id']);
        // Nothing was saved (one transaction). Say which part failed.
        $errors['_form'] = $step === 'proof'
            ? 'The payment was not saved: the proof file could not be recorded. ' . db_error_detail($e)
            : db_error_message($e);
        if ($step === 'proof') {
            error_log('[' . APP_NAME . '] Saving the payment proof failed: ' . $e->getMessage());
        }
        return compact('fields', 'data', 'errors', 'groups', 'chosen', 'hasProof', 'proofRequired', 'payment');
    }

    log_activity($payment ? 'update' : 'create', 'borrower_payment', $saved[0] ?? null,
        ($payment ? 'Borrower updated payment of ' : 'Borrower submitted payment of ') . format_money($data['amount']), null, $data);
    $message = $payment
        ? 'Your payment was updated and sent back to the owner for review.'
        : 'Payment of ' . format_money($data['amount']) . ' was submitted' . (count($saved) > 1 ? ' (' . count($saved) . ' items)' : '')
            . '. The owner reviews it; it counts toward your balance once confirmed.';

    return ['saved' => $saved, 'message' => $message] + $state;
}

/** The form (same markup wherever it is opened). */
function render_payment_form(array $s, string $action, string $cancelUrl): string
{
    ob_start();
    $errors = $s['errors'];
    $payment = $s['payment'];
    ?>
    <?php if ($payment && $payment['status'] === 'correction_required' && $payment['correction_reason']): ?>
        <div class="alert alert--warning" role="status"><?= icon('alert') ?>
            <span><strong>What to fix:</strong> <?= e($payment['correction_reason']) ?></span></div>
    <?php endif; ?>
    <form method="post" action="<?= e($action) ?>" enctype="multipart/form-data" class="form-card form-card--wide" novalidate data-validate>
        <?= csrf_field() ?>
        <?= $payment ? '' : form_once_field('payment_form') ?>
        <input type="hidden" name="MAX_FILE_SIZE" value="<?= e(DOCUMENT_MAX_BYTES) ?>">
        <?php if ($errors): ?>
            <div class="alert alert--error" role="alert"><?= icon('alert') ?><span><?= e($errors['_form'] ?? 'Please correct the highlighted fields below.') ?></span></div>
        <?php endif; ?>
        <?= render_fields($s['fields'], $s['data'], $errors) ?>

        <?= render_payment_for_table($s['groups'], $s['chosen'], $errors) ?>

        <div class="form-grid">
            <div class="form-group form-group--full">
                <label for="f_proof">Proof of payment<?= $s['hasProof'] || !$s['proofRequired'] ? ' <span class="text-muted">(optional)</span>' : ' <span class="required" aria-hidden="true">*</span>' ?></label>
                <input type="file" id="f_proof" name="proof" accept=".jpg,.jpeg,.png,.gif,.webp,.pdf"<?= $s['proofRequired'] && !$s['hasProof'] ? ' required' : '' ?>
                       class="<?= isset($errors['proof']) ? 'is-invalid' : '' ?>" aria-describedby="f_proof-help f_proof-error">
                <p class="field-help" id="f_proof-help">Screenshot, photo or PDF of the receipt, up to <?= e(DOCUMENT_MAX_BYTES / 1024 / 1024) ?> MB.
                    <?= $s['hasProof'] ? 'A proof is already attached; add another only if needed.'
                        : ($s['proofRequired'] ? 'Required for your account.' : 'Optional for family members, but it helps the owner approve your payment faster.') ?></p>
                <p class="field-error" id="f_proof-error" <?= isset($errors['proof']) ? '' : 'hidden' ?>><?= e($errors['proof'] ?? '') ?></p>
            </div>
        </div>
        <div class="form-actions">
            <a href="<?= e($cancelUrl) ?>" class="btn btn--outline">Cancel</a>
            <button type="submit" class="btn btn--primary" data-loading-text="Sending…"><?= icon('check') ?> <span class="btn__label"><?= $payment ? 'Save and resubmit' : 'Submit payment' ?></span></button>
        </div>
    </form>
    <?php

    return (string) ob_get_clean();
}

/**
 * "What is this payment for?" - every open item of the borrower, one row per
 * installment, with a box for the amount (assets/js/portal-pay.js keeps the
 * assigned / not assigned summary). Shared by the borrower form and the staff
 * "Receive payment" form.
 */
function render_payment_for_table(array $groups, array $chosen, array $errors, ?string $help = null): string
{
    ob_start();
    ?>
        <fieldset class="pay-for" data-pay-for>
            <legend>What is this payment for?</legend>
            <p class="field-help"><?= $help !== null ? e($help) : 'Tick the items or installments you are paying and the amount for each. Late charges on an item are paid first from what you put on it. '
                . (array_filter($groups, static fn ($g) => $g['kind'] === 'obligation') ? 'Anything you do not assign is posted by the owner.' : 'Put the whole amount on the items you are paying.') ?></p>
            <?php if (isset($errors['selection'])): ?><p class="field-error"><?= e($errors['selection']) ?></p><?php endif; ?>
            <?php if (!$groups): ?>
                <p class="empty-inline">You have nothing open to pay right now.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table table--cards table--compact pay-for__table">
                        <thead><tr><th scope="col" class="col-check"><span class="visually-hidden">Pay</span></th><th scope="col">Item</th><th scope="col">Due</th><th scope="col" class="num">To pay</th><th scope="col" class="num">Amount you are paying</th></tr></thead>
                        <?php foreach ($groups as $g): ?>
                            <tbody>
                            <tr class="pay-for__group"><td colspan="5"><strong><?= e($g['title']) ?></strong>
                                <?= $g['subtitle'] ? ' · ' . e($g['subtitle']) : '' ?>
                                <span class="text-muted"> · total to pay <?= e(format_money($g['owed'])) ?><?= $g['penalties'] > 0 ? ' (incl. ' . e(format_money($g['penalties'])) . ' late charges)' : '' ?></span></td></tr>
                            <?php if ($g['months']): ?>
                                <tr class="pay-for__months"><td colspan="5"><span class="text-muted">Monthly amounts (paid oldest month first):</span>
                                    <?php foreach (array_slice($g['months'], 0, 4) as $m): ?>
                                        <span class="pay-for__month">#<?= e($m['installment_number']) ?> · <?= e(format_date($m['due_date'])) ?> · <?= e(format_money($m['remaining_amount'])) ?><?= $m['status'] === 'overdue' ? ' <span class="status status--danger">Overdue</span>' : '' ?></span>
                                    <?php endforeach; ?>
                                    <?= count($g['months']) > 4 ? '<span class="text-muted">+' . e(count($g['months']) - 4) . ' more</span>' : '' ?>
                                    <a href="<?= e($g['link']) ?>">See schedule</a></td></tr>
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
                                               value="<?= e($on ? $chosen[$r['key']] : '') ?>" <?= $on ? '' : 'disabled' ?> aria-label="Amount for <?= e($g['title'] . ' ' . $r['label']) ?>">
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        <?php endforeach; ?>
                    </table>
                </div>
                <p class="pay-for__summary" aria-live="polite">Assigned <strong data-pay-assigned>₱0.00</strong> of <strong data-pay-total>₱0.00</strong> paid ·
                    not assigned <strong data-pay-rest>₱0.00</strong><?= $help === null && array_filter($groups, static fn ($g) => $g['kind'] === 'obligation') ? ' <span class="text-muted">(the owner will post it)</span>' : '' ?></p>
            <?php endif; ?>
        </fieldset>
    <?php

    return (string) ob_get_clean();
}
