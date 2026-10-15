<?php
/**
 * Demand Letters - the printable letter, its record and status actions.
 * POST status=... moves the letter along its workflow.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/letter_functions.php';

$currentUser = require_admin();

$letter = find_or_redirect(letter_find(request_id()), 'pages/demand_letters/index.php', 'Demand letter');
$id = $letter['id'];
$next = LETTER_TRANSITIONS[$letter['status']] ?? [];

if (is_post()) {
    require_csrf('pages/demand_letters/view.php?id=' . $id);
    $status = input_string($_POST, 'status');

    if (!in_array($status, $next, true)) {
        flash('error', 'That status change is not allowed.');
    } else {
        $update = ['status' => $status];
        if ($status === 'sent') {
            $update['sent_at'] = date('Y-m-d H:i:s');
        } elseif ($status === 'acknowledged') {
            $update['acknowledged_at'] = date('Y-m-d H:i:s');
        }
        db_update('demand_letters', $update, $id);
        if ($status === 'sent') {
            notify_borrower($letter['borrower_id'], 'demand_letter', 'New notice: ' . $letter['letter_number'],
                'The owner sent you a notice. Please open it and acknowledge that you have read it.', 'demand_letter', $id);
        }
        log_activity('status_change', 'demand_letter', $id, $letter['letter_number'] . ' changed to ' . $status, ['status' => $letter['status']], ['status' => $status]);
        flash('success', 'Letter ' . $letter['letter_number'] . ' is now ' . humanize($status) . '.');
    }
    redirect('pages/demand_letters/view.php', ['id' => $id]);
}

$pageTitle = 'Demand Letter';
$activeNav = 'demand_letters';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb(['Demand Letters' => url('pages/demand_letters/index.php'), $letter['letter_number'] => null]) ?>

<section class="page-header no-print">
    <div>
        <h2 class="page-header__title"><?= e($letter['letter_number']) ?></h2>
        <p class="page-header__subtitle"><?= e(borrower_name($letter)) ?> · <?= e(format_date($letter['generated_date'])) ?> · <?= badge($letter['status']) ?></p>
    </div>
    <div class="page-header__actions">
        <a href="<?= e(url('pages/demand_letters/index.php')) ?>" class="btn btn--outline"><?= icon('arrow-left') ?> <span>Back</span></a>
        <button type="button" class="btn btn--outline" data-print><?= icon('printer') ?> <span>Print letter</span></button>
        <?php foreach ($next as $status): ?>
            <?= post_button(url('pages/demand_letters/view.php', ['id' => $id]), ['status' => $status],
                ['approved' => 'Approve', 'sent' => 'Mark sent', 'acknowledged' => 'Mark acknowledged', 'resolved' => 'Mark resolved', 'cancelled' => 'Cancel letter', 'draft' => 'Back to draft'][$status],
                $status === 'cancelled' ? 'x' : 'check', $status === 'cancelled' ? 'btn btn--outline' : 'btn btn--outline',
                $status === 'cancelled' ? 'Cancel this demand letter? It stays on record.' : null, false, $status === 'cancelled' ? 'Cancel letter?' : null) ?>
        <?php endforeach; ?>
        <?php if (in_array($letter['status'], LETTER_EDITABLE_STATUSES, true)): ?>
            <a data-modal-form href="<?= e(url('pages/demand_letters/edit.php', ['id' => $id])) ?>" class="btn btn--primary"><?= icon('edit') ?> <span>Edit</span></a>
        <?php endif; ?>
    </div>
</section>

<section class="panel print-document">
    <div class="print-document__head">
        <p class="print-header__system"><?= e(APP_NAME) ?></p>
        <h2>Demand Letter</h2>
        <p class="text-muted">No. <?= e($letter['letter_number']) ?> · <?= e(format_date($letter['generated_date'])) ?></p>
    </div>
    <div class="document-body"><?= e($letter['content']) ?></div>
</section>

<div class="detail-layout no-print">
    <section class="panel">
        <header class="panel__header"><h3>Letter record</h3></header>
        <?= render_details([
            'Letter number'      => e($letter['letter_number']),
            'Borrower'           => related_link($letter['borrower_id'], borrower_name($letter) . ' (' . $letter['borrower_number'] . ')', 'pages/borrowers/view.php'),
            'Obligation'         => related_link($letter['obligation_id'], $letter['obligation_number'], 'pages/obligations/view.php'),
            'Agreement'          => related_link($letter['agreement_id'], $letter['agreement_number'], 'pages/agreements/view.php'),
            'Template'           => related_link($letter['template_id'], $letter['template_name'], 'pages/letter_templates/view.php'),
            'Original amount'    => show_money($letter['original_amount']),
            'Total paid'         => show_money($letter['total_paid']),
            'Remaining balance'  => show_money($letter['remaining_balance']),
            'Documented charges' => show_money($letter['documented_charges']),
            'Status'             => badge($letter['status']),
            'Sent'               => show($letter['sent_at'] ? format_datetime($letter['sent_at']) : null),
            'Acknowledged'       => show($letter['acknowledged_at'] ? format_datetime($letter['acknowledged_at']) : null),
            'File copy'          => related_link($letter['document_id'], $letter['original_filename'], 'pages/documents/view.php'),
            'Created'            => e(format_datetime($letter['created_at'])) . ($letter['created_by_name'] ? ' by ' . e($letter['created_by_name']) : ''),
            'Last updated'       => e(format_datetime($letter['updated_at'])),
        ]) ?>
    </section>
    <div class="detail-side">
        <section class="panel panel--danger">
            <header class="panel__header"><h3>Delete</h3></header>
            <?php if ($letter['status'] !== 'draft'): ?>
                <p class="field-help">Only drafts can be deleted. Approved or sent letters are kept; cancel instead.</p>
            <?php else: ?>
                <?= post_button(url('pages/demand_letters/delete.php'), ['id' => $id], 'Delete draft', 'trash', 'btn btn--danger btn--block',
                    'Delete draft letter ' . $letter['letter_number'] . '? This cannot be undone.') ?>
            <?php endif; ?>
        </section>
    </div>
</div>

<?php require APP_ROOT . '/includes/footer.php'; ?>
