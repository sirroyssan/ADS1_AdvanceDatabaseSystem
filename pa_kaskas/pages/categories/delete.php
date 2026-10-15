<?php
/**
 * Categories - Delete.
 *
 * GET  delete.php?id=...  -> confirmation page (works without JavaScript)
 * POST action=delete      -> check dependencies, then DELETE
 * POST action=deactivate  -> soft delete: is_active = 0 (for categories in use)
 *
 * A category used by sub-categories, merchants or transactions is never
 * hard-deleted, even if the database has no foreign key to stop it.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/category_functions.php';

$currentUser = require_admin();

$id = is_post() ? input_string($_POST, 'id') : input_string($_GET, 'id');
$category = category_find($id);

if ($category === null) {
    flash('error', 'Category not found. It may have already been deleted.');
    redirect('pages/categories/index.php');
}

if (is_post()) {
    require_csrf('pages/categories/delete.php?id=' . rawurlencode($category['id']));

    $action = input_string($_POST, 'action');

    if ($action === 'deactivate') {
        if ((int) $category['is_active'] === 0) {
            flash('info', 'Category "' . $category['name'] . '" is already inactive.');
        } else {
            $stmt = db()->prepare('UPDATE categories SET is_active = 0 WHERE id = :id');
            $stmt->execute([':id' => $category['id']]);
            log_activity('deactivate', 'category', $category['id'], 'Deactivated category "' . $category['name'] . '"',
                ['is_active' => 1], ['is_active' => 0]);
            flash('success', 'Category "' . $category['name'] . '" was deactivated.');
        }
        redirect('pages/categories/index.php');
    }

    if ($action !== 'delete') {
        redirect('pages/categories/index.php');
    }

    $pdo = db();

    try {
        $pdo->beginTransaction();

        // Lock the row so nothing starts using it between the check and the delete.
        $lock = $pdo->prepare('SELECT id FROM categories WHERE id = :id FOR UPDATE');
        $lock->execute([':id' => $category['id']]);

        $usage = category_usage($category['id']);

        if ($usage['total'] > 0) {
            $pdo->rollBack();
            flash('error', 'Unable to delete "' . $category['name'] . '" because it is used by '
                . category_usage_text($usage) . '. You can deactivate it instead.');
            redirect('pages/categories/delete.php', ['id' => $category['id']]);
        }

        $stmt = $pdo->prepare('DELETE FROM categories WHERE id = :id');
        $stmt->execute([':id' => $category['id']]);
        $pdo->commit();

        log_activity('delete', 'category', $category['id'], 'Deleted category "' . $category['name'] . '"',
            array_intersect_key($category, array_flip(['name', 'parent_id', 'description', 'is_active', 'created_at'])));
        flash('success', 'Category "' . $category['name'] . '" was deleted.');
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('error', category_db_error_message($e));
    }

    redirect('pages/categories/index.php');
}

// ---- GET: confirmation page ------------------------------------------
$usage = category_usage($category['id']);

$pageTitle   = 'Delete Category';
$activeNav   = 'categories';
$pageStyles  = ['assets/css/categories.css'];
$pageScripts = ['assets/js/categories.js'];
require APP_ROOT . '/includes/header.php';
?>

<nav class="breadcrumb no-print" aria-label="Breadcrumb">
    <a href="<?= e(url('pages/dashboard.php')) ?>">Dashboard</a>
    <span aria-hidden="true">/</span>
    <a href="<?= e(url('pages/categories/index.php')) ?>">Categories</a>
    <span aria-hidden="true">/</span>
    <span aria-current="page">Delete</span>
</nav>

<section class="confirm-card" aria-labelledby="confirm-page-title">
    <div class="status-icon status-icon--danger"><?= icon('trash', 'icon icon--xl') ?></div>
    <h2 id="confirm-page-title">Delete “<?= e($category['name']) ?>”?</h2>

    <?php if ($usage['total'] > 0): ?>
        <p>
            This category cannot be deleted because it is used by
            <strong><?= e(category_usage_text($usage)) ?></strong>.
        </p>
        <p class="text-muted">
            <?php if ((int) $category['is_active'] === 1): ?>
                Deactivate it instead: it stays linked to existing records but is marked as no longer in use.
            <?php else: ?>
                It is already inactive.
            <?php endif; ?>
        </p>
        <div class="confirm-card__actions">
            <a href="<?= e(url('pages/categories/view.php', ['id' => $category['id']])) ?>" class="btn btn--outline">Cancel</a>
            <?php if ((int) $category['is_active'] === 1): ?>
                <form method="post" action="<?= e(url('pages/categories/delete.php')) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= e($category['id']) ?>">
                    <input type="hidden" name="action" value="deactivate">
                    <button type="submit" class="btn btn--warning" data-loading-text="Deactivating…">
                        <?= icon('power') ?> <span class="btn__label">Deactivate</span>
                    </button>
                </form>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <p>Are you sure you want to delete this record? This cannot be undone.</p>
        <div class="confirm-card__actions">
            <a href="<?= e(url('pages/categories/view.php', ['id' => $category['id']])) ?>" class="btn btn--outline">Cancel</a>
            <form method="post" action="<?= e(url('pages/categories/delete.php')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= e($category['id']) ?>">
                <input type="hidden" name="action" value="delete">
                <button type="submit" class="btn btn--danger" data-loading-text="Deleting…">
                    <?= icon('trash') ?> <span class="btn__label">Delete</span>
                </button>
            </form>
        </div>
    <?php endif; ?>
</section>

<?php require APP_ROOT . '/includes/footer.php'; ?>
