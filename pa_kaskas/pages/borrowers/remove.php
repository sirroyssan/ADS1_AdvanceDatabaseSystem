<?php
/**
 * Borrowers - delete or merge (opened in a modal).
 *   No records yet          -> delete it (delete.php).
 *   Records + a duplicate   -> merge it into the record that is kept: its
 *                              records move there, then it is deleted
 *                              (borrower_merge(); reason required, logged).
 *   Records, no duplicate   -> it cannot be deleted; mark it inactive.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/borrower_functions.php';

$currentUser = require_admin();

$borrower = find_or_redirect(borrower_find(request_id()), 'pages/borrowers/index.php', 'Borrower');
$back = 'pages/borrowers/view.php?id=' . $borrower['id'];
$name = borrower_full_name($borrower);
$usage = borrower_usage($borrower['id']);
$duplicates = borrower_duplicates($borrower, $borrower['id']);
$error = null;
$reason = '';
$intoId = $duplicates[0]['id'] ?? '';

if (is_post()) {
    require_csrf($back);
    $reason = trim(mb_substr(input_string($_POST, 'reason'), 0, 1000));
    $intoId = input_string($_POST, 'into_id');
    $into = in_array($intoId, array_column($duplicates, 'id'), true) ? borrower_find($intoId) : null;
    if ($into === null) {
        $error = 'Choose the borrower record to keep.';
    } elseif (mb_strlen($reason) < 5) {
        $error = 'Enter the reason for merging (at least 5 characters).';
    } else {
        try {
            $moved = borrower_merge($borrower, $into, $reason, $currentUser);
            flash('success', $borrower['borrower_number'] . ' was merged into ' . $into['borrower_number'] . ' ' . borrower_full_name($into)
                . ($moved ? ' (' . array_sum($moved) . ' record(s) moved)' : '') . ' and deleted.');
            redirect('pages/borrowers/view.php', ['id' => $into['id']]);
        } catch (DomainException $e) {
            $error = $e->getMessage();
        } catch (PDOException $e) {
            $error = db_error_message($e);
        }
    }
}

$pageTitle = 'Delete borrower';
$activeNav = 'borrowers';
require APP_ROOT . '/includes/header.php';
?>

<section class="page-header">
    <div>
        <h2 class="page-header__title"><?= $duplicates && $usage['total'] > 0 ? 'Merge or delete' : 'Delete' ?> <?= e($name) ?></h2>
        <p class="page-header__subtitle"><?= e($borrower['borrower_number']) ?> · <?= badge($borrower['status']) ?></p>
    </div>
</section>

<?php if ($error !== null): ?><div class="alert alert--error" role="alert"><?= icon('alert') ?><span><?= e($error) ?></span></div><?php endif; ?>

<?php if ($duplicates): ?>
    <div class="alert alert--warning" role="status"><?= icon('alert') ?><span>Possible duplicate of: <?= e(borrower_duplicate_text($duplicates)) ?>.</span></div>
<?php endif; ?>

<?php if ($usage['total'] === 0): ?>
    <form method="post" action="<?= e(url('pages/borrowers/delete.php')) ?>" class="form-card">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= e($borrower['id']) ?>">
        <input type="hidden" name="action" value="delete">
        <p>This borrower has no purchases, obligations, payments or other records yet, so the record can be deleted with its addresses and contacts. This cannot be undone; the activity log keeps a copy.</p>
        <div class="form-actions">
            <a href="<?= e(url($back)) ?>" class="btn btn--outline">Cancel</a>
            <button type="submit" class="btn btn--danger" data-loading-text="Deleting…"><?= icon('trash') ?> <span class="btn__label">Delete borrower</span></button>
        </div>
    </form>
<?php elseif ($duplicates): ?>
    <form method="post" action="<?= e(url('pages/borrowers/remove.php', ['id' => $borrower['id']])) ?>" class="form-card" novalidate data-validate>
        <?= csrf_field() ?>
        <p>This record has <?= e(usage_text($usage)) ?>, so it cannot simply be deleted. Merge it into the record you keep: everything moves there
            (balances, payments and history stay complete), then <?= e($borrower['borrower_number']) ?> is deleted.</p>
        <fieldset class="form-group">
            <legend>Keep this record <span class="required" aria-hidden="true">*</span></legend>
            <?php foreach ($duplicates as $d): ?>
                <label class="choice">
                    <input type="radio" name="into_id" value="<?= e($d['id']) ?>" required <?= $d['id'] === $intoId ? 'checked' : '' ?>>
                    <span><strong><?= e($d['borrower_number']) ?></strong> <?= e(borrower_full_name($d)) ?> · <?= e($d['matched']) ?><?php if ($d['primary_mobile'] || $d['primary_email']): ?> · <?= e(trim($d['primary_mobile'] . ' ' . $d['primary_email'])) ?><?php endif; ?></span>
                </label>
            <?php endforeach; ?>
        </fieldset>
        <div class="form-group">
            <label for="f_merge_reason">Reason <span class="required" aria-hidden="true">*</span></label>
            <textarea id="f_merge_reason" name="reason" rows="3" maxlength="1000" required minlength="5"
                      placeholder="e.g. Added twice; same person"><?= e($reason) ?></textarea>
            <p class="field-help">Saved in the activity log with a copy of the deleted record and the number of records moved.</p>
        </div>
        <div class="form-actions">
            <a href="<?= e(url($back)) ?>" class="btn btn--outline">Cancel</a>
            <button type="submit" class="btn btn--danger" data-loading-text="Merging…"><?= icon('trash') ?> <span class="btn__label">Merge and delete <?= e($borrower['borrower_number']) ?></span></button>
        </div>
    </form>
<?php else: ?>
    <form method="post" action="<?= e(url('pages/borrowers/delete.php')) ?>" class="form-card">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= e($borrower['id']) ?>">
        <input type="hidden" name="action" value="deactivate">
        <p>This borrower has <?= e(usage_text($usage)) ?>. Financial history is never erased, so the record cannot be deleted.
            <?= $borrower['status'] === 'inactive' ? 'It is already inactive.' : 'Mark it inactive instead; the history stays.' ?></p>
        <div class="form-actions">
            <a href="<?= e(url($back)) ?>" class="btn btn--outline"><?= $borrower['status'] === 'inactive' ? 'Close' : 'Cancel' ?></a>
            <?php if ($borrower['status'] !== 'inactive'): ?>
                <button type="submit" class="btn btn--warning" data-loading-text="Saving…"><?= icon('power') ?> <span class="btn__label">Mark as inactive</span></button>
            <?php endif; ?>
        </div>
    </form>
<?php endif; ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
