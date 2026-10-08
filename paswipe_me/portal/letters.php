<?php
/**
 * Borrower portal - notices (demand letters the owner has sent).
 */

require_once __DIR__ . '/../includes/auth_check.php';

[$currentUser, $borrower] = require_borrower();

$letters = db_all(
    'SELECT id, letter_number, generated_date, sent_at, remaining_balance, status FROM demand_letters
      WHERE borrower_id = :b AND status IN (' . sql_in_list(PORTAL_LETTER_STATUSES) . ')
      ORDER BY COALESCE(sent_at, generated_date) DESC',
    [':b' => $borrower['id']]
);

$pageTitle = 'Notices';
$activeNav = 'portal_letters';
$layout = 'portal';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb(['Notices' => null]) ?>

<section class="page-header">
    <div>
        <h2 class="page-header__title">Notices</h2>
        <p class="page-header__subtitle">Payment reminders and demand letters sent to you. Please open and acknowledge new ones.</p>
    </div>
</section>

<section class="panel panel--flush">
    <?php if (!$letters): ?>
        <div class="empty-state"><div class="empty-state__icon"><?= icon('mail-alert', 'icon icon--xl') ?></div>
            <h3>No notices.</h3><p class="text-muted">You have not received any notice.</p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table--cards">
                <thead><tr><th scope="col">Notice</th><th scope="col">Sent</th><th scope="col" class="num">Balance stated</th><th scope="col">Status</th></tr></thead>
                <tbody>
                <?php foreach ($letters as $l): ?>
                    <tr>
                        <td data-label="Notice"><a class="table-link" href="<?= e(url('portal/letter.php', ['id' => $l['id']])) ?>"><?= e($l['letter_number']) ?></a>
                            <?= $l['status'] === 'sent' ? ' <span class="status status--danger">New</span>' : '' ?></td>
                        <td data-label="Sent"><?= e($l['sent_at'] ? format_datetime($l['sent_at']) : format_date($l['generated_date'])) ?></td>
                        <td data-label="Balance stated" class="num"><?= show_money($l['remaining_balance']) ?></td>
                        <td data-label="Status"><?= badge($l['status']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php require APP_ROOT . '/includes/footer.php'; ?>
