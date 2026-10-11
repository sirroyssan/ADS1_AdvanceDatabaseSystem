<?php
/**
 * Reset test data - remove the signed-in owner's test financial records and
 * start recording from zero, keeping cards, borrowers, rules and settings
 * (includes/data_reset.php). GET shows the preview; POST with the typed
 * confirmation runs it; the report is shown once after the redirect.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once APP_ROOT . '/includes/data_reset.php';

$currentUser = require_admin();
$ownerId = owner_id();
$back = 'pages/data_reset/index.php';

if (is_post()) {
    require_csrf($back);
    if (input_string($_POST, 'confirm') !== 'RESET') {
        flash('error', 'Type RESET in capital letters to confirm. Nothing was changed.');
        redirect($back);
    }
    if (!form_once_use('data_reset', input_string($_POST, 'once'))) {
        flash('info', 'This reset was already run.');
        redirect($back);
    }
    try {
        $_SESSION['data_reset_report'] = data_reset_run($ownerId, $currentUser['id']);
        flash($_SESSION['data_reset_report']['issues'] ? 'warning' : 'success', 'Test data was reset. Backup batch ' . $_SESSION['data_reset_report']['batch'] . ' was saved.');
    } catch (Throwable $e) {
        error_log('[' . APP_NAME . '] Data reset failed: ' . $e->getMessage());
        flash('error', 'The reset was cancelled and nothing was changed: ' . ($e instanceof PDOException ? db_error_message($e) : $e->getMessage()));
    }
    redirect($back);
}

$report = $_SESSION['data_reset_report'] ?? null;
unset($_SESSION['data_reset_report']);
$counts = data_reset_counts(data_reset_scope($ownerId));
$total = array_sum($counts);
$preserved = data_reset_preserved_counts($ownerId);
$batches = data_reset_batches($ownerId);

$pageTitle = 'Reset Test Data';
$activeNav = 'data_reset';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb(['Reset test data' => null]) ?>

<section class="page-header">
    <div>
        <h2 class="page-header__title">Reset test data</h2>
        <p class="page-header__subtitle">Removes your test transactions, installments, loans, advances, obligations, repayments, penalties and prepared statements so you can start from zero.
            Your cards, borrowers, rules and settings are kept. Other users' records are never touched.</p>
    </div>
</section>

<?php if ($report): ?>
    <section class="panel subpanel" aria-labelledby="report-title">
        <header class="panel__header"><h3 id="report-title"><?= icon('check') ?> Reset report — <?= e($report['batch']) ?></h3></header>
        <?php if ($report['issues']): ?>
            <div class="alert alert--warning"><?= icon('alert') ?><span><?= implode('<br>', array_map('e', $report['issues'])) ?></span></div>
        <?php else: ?>
            <div class="alert alert--success"><?= icon('check') ?><span>Verified: nothing in scope is left, no orphaned statement lines or notifications, and every preserved count is unchanged.</span></div>
        <?php endif; ?>
        <div class="table-wrap">
            <table class="table table--cards table--compact">
                <thead><tr><th scope="col">Table</th><th scope="col" class="num">Removed</th></tr></thead>
                <tbody>
                <?php foreach (array_filter($report['removed']) as $table => $n): ?>
                    <tr><td data-label="Table"><?= e($table) ?></td><td data-label="Removed" class="num"><?= e($n) ?></td></tr>
                <?php endforeach; ?>
                <tr><td data-label="Table">documents (hidden; files kept)</td><td data-label="Removed" class="num"><?= e($report['documents_flagged']) ?></td></tr>
                </tbody>
            </table>
        </div>
        <h4 class="form-section">Preserved (before → after)</h4>
        <div class="table-wrap">
            <table class="table table--cards table--compact">
                <thead><tr><th scope="col">Data</th><th scope="col" class="num">Before</th><th scope="col" class="num">After</th></tr></thead>
                <tbody>
                <?php foreach ($report['preserved_before'] as $label => $n): ?>
                    <tr><td data-label="Data"><?= e($label) ?></td><td data-label="Before" class="num"><?= e($n) ?></td><td data-label="After" class="num"><?= e($report['preserved_after'][$label]) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <h4 class="form-section">Cards recalculated from the remaining records</h4>
        <div class="table-wrap">
            <table class="table table--cards table--compact">
                <thead><tr><th scope="col">Card</th><th scope="col" class="num">Opening balance</th><th scope="col" class="num">Used</th><th scope="col" class="num">Available</th><th scope="col" class="num">Limit</th></tr></thead>
                <tbody>
                <?php foreach ($report['card_usage'] as $c): ?>
                    <tr><td data-label="Card"><?= e($c['card_nickname']) ?> •••• <?= e($c['last_four']) ?></td>
                        <td data-label="Opening balance" class="num"><?= e(format_money($c['opening'])) ?></td>
                        <td data-label="Used" class="num"><?= e(format_money($c['used'])) ?></td>
                        <td data-label="Available" class="num"><?= e(format_money($c['available'])) ?></td>
                        <td data-label="Limit" class="num"><?= e(format_money($c['credit_limit'])) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="field-help">Used credit now comes only from the card's opening balance (and anything not in the reset). Dashboard, borrower and obligation totals are calculated live, so they no longer include the removed records.</p>
    </section>
<?php endif; ?>

<div class="detail-layout">
    <section class="panel">
        <header class="panel__header"><h3><?= icon('trash') ?> Will be removed (<?= e($total) ?> records)</h3></header>
        <?php if ($total === 0): ?>
            <p class="empty-inline">There is nothing to reset.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table table--cards table--compact">
                    <thead><tr><th scope="col">Table</th><th scope="col" class="num">Records</th></tr></thead>
                    <tbody>
                    <?php foreach (array_filter($counts) as $table => $n): ?>
                        <tr><td data-label="Table"><?= e($table) ?></td><td data-label="Records" class="num"><?= e($n) ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="field-help">Statements the app prepared are rebuilt afterwards from what remains. Bank SOAs you recorded are kept.
                Uploaded files used only by removed records are hidden, not erased.</p>
        <?php endif; ?>
    </section>

    <div class="detail-side">
        <section class="panel">
            <header class="panel__header"><h3><?= icon('shield') ?> Kept</h3></header>
            <ul class="child-list">
                <?php foreach ($preserved as $label => $n): ?>
                    <li><span><?= e($label) ?></span><strong><?= e($n) ?></strong></li>
                <?php endforeach; ?>
            </ul>
        </section>

        <?php if ($total > 0): ?>
            <section class="panel panel--danger no-print">
                <header class="panel__header"><h3>Confirm the reset</h3></header>
                <p class="field-help">Every removed record is first copied into the backup (table <code>backup_reset_rows</code>), and the reset runs as one database transaction: it completes fully or changes nothing.</p>
                <form method="post" action="<?= e(url('pages/data_reset/index.php')) ?>" class="status-form">
                    <?= csrf_field() ?>
                    <?= form_once_field('data_reset') ?>
                    <?= render_fields(['confirm' => ['label' => 'Type RESET to confirm', 'type' => 'text', 'max' => 10, 'required' => true, 'attrs' => ['autocomplete' => 'off']]], [], []) ?>
                    <button type="submit" class="btn btn--danger btn--block" data-loading-text="Resetting…"><?= icon('trash') ?> <span class="btn__label">Reset <?= e($total) ?> test records</span></button>
                </form>
            </section>
        <?php endif; ?>

        <section class="panel">
            <header class="panel__header"><h3><?= icon('folder') ?> Backups</h3></header>
            <?php if (!$batches): ?>
                <p class="empty-inline">No reset has been run yet.</p>
            <?php else: ?>
                <ul class="child-list">
                    <?php foreach ($batches as $bk): ?>
                        <li><span><?= e($bk['batch']) ?> · <?= e($bk['rows_saved']) ?> rows</span>
                            <a href="<?= e(url('pages/data_reset/download.php', ['batch' => $bk['batch']])) ?>" class="btn btn--sm btn--outline" download><?= icon('folder') ?> <span>SQL</span></a></li>
                    <?php endforeach; ?>
                </ul>
                <p class="field-help">The SQL file restores that batch's records (run it in phpMyAdmin on this database).</p>
            <?php endif; ?>
        </section>
    </div>
</div>

<?php require APP_ROOT . '/includes/footer.php'; ?>
