<?php
/**
 * Categories - View details.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/category_functions.php';

$currentUser = require_admin();

$category = category_find(input_string($_GET, 'id'));

if ($category === null) {
    flash('error', 'Category not found. It may have been deleted.');
    redirect('pages/categories/index.php');
}

$map   = category_all_map();
$path  = category_path($category['id'], $map);
$usage = category_usage($category['id']);

$stmt = db()->prepare('SELECT id, name, is_active FROM categories WHERE parent_id = :id ORDER BY name');
$stmt->execute([':id' => $category['id']]);
$children = $stmt->fetchAll();

$pageTitle   = 'Category Details';
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
    <span aria-current="page"><?= e($category['name']) ?></span>
</nav>

<div class="print-header">
    <p class="print-header__system"><?= e(APP_NAME) ?></p>
    <h2 class="print-header__title">Category Record</h2>
    <p class="print-header__meta">Printed: <?= e(date('F j, Y g:i A')) ?></p>
</div>

<section class="page-header">
    <div>
        <h2 class="page-header__title"><?= e($category['name']) ?></h2>
        <p class="page-header__subtitle"><?= e($path) ?></p>
    </div>
    <div class="page-header__actions no-print">
        <a href="<?= e(url('pages/categories/index.php')) ?>" class="btn btn--outline"><?= icon('arrow-left') ?> <span>Back</span></a>
        <button type="button" class="btn btn--outline" data-print><?= icon('printer') ?> <span>Print</span></button>
        <a href="<?= e(url('pages/categories/edit.php', ['id' => $category['id']])) ?>" class="btn btn--primary"><?= icon('edit') ?> <span>Edit</span></a>
    </div>
</section>

<div class="detail-layout">
    <section class="panel" aria-labelledby="details-title">
        <header class="panel__header"><h3 id="details-title">Record details</h3></header>
        <dl class="detail-list">
            <div><dt>Name</dt><dd><?= e($category['name']) ?></dd></div>
            <div><dt>Full path</dt><dd><?= e($path) ?></dd></div>
            <div>
                <dt>Parent category</dt>
                <dd>
                    <?php if ($category['parent_id'] === null): ?>
                        <span class="text-muted">None (top-level)</span>
                    <?php else: ?>
                        <a href="<?= e(url('pages/categories/view.php', ['id' => $category['parent_id']])) ?>"><?= e($category['parent_name']) ?></a>
                    <?php endif; ?>
                </dd>
            </div>
            <div>
                <dt>Description</dt>
                <dd class="pre-line"><?= $category['description'] === null ? '<span class="text-muted">—</span>' : e($category['description']) ?></dd>
            </div>
            <div>
                <dt>Status</dt>
                <dd>
                    <?php if ((int) $category['is_active'] === 1): ?>
                        <span class="status status--success"><?= icon('check', 'icon icon--sm') ?> Active</span>
                    <?php else: ?>
                        <span class="status status--muted"><?= icon('x', 'icon icon--sm') ?> Inactive</span>
                    <?php endif; ?>
                </dd>
            </div>
            <div><dt>Created</dt><dd><?= e(format_datetime($category['created_at'])) ?></dd></div>
            <div><dt>Record ID</dt><dd><code><?= e($category['id']) ?></code></dd></div>
        </dl>
    </section>

    <div class="detail-side">
        <section class="panel" aria-labelledby="usage-title">
            <header class="panel__header"><h3 id="usage-title">Usage</h3></header>
            <ul class="usage-counts">
                <li><span>Sub-categories</span><strong><?= e($usage['children']) ?></strong></li>
                <li><span>Merchants</span><strong><?= e($usage['merchants']) ?></strong></li>
                <li><span>Transactions</span><strong><?= e($usage['transactions']) ?></strong></li>
            </ul>
            <?php if ($usage['total'] > 0): ?>
                <p class="field-help">This category is in use and cannot be deleted. You can deactivate it instead.</p>
            <?php endif; ?>
        </section>

        <section class="panel" aria-labelledby="children-title">
            <header class="panel__header">
                <h3 id="children-title">Sub-categories</h3>
                <a class="btn btn--sm btn--outline no-print" href="<?= e(url('pages/categories/create.php', ['parent_id' => $category['id']])) ?>">
                    <?= icon('plus') ?> <span>Add</span>
                </a>
            </header>
            <?php if (!$children): ?>
                <p class="empty-inline">No sub-categories.</p>
            <?php else: ?>
                <ul class="child-list">
                    <?php foreach ($children as $child): ?>
                        <li>
                            <a href="<?= e(url('pages/categories/view.php', ['id' => $child['id']])) ?>"><?= e($child['name']) ?></a>
                            <?php if ((int) $child['is_active'] !== 1): ?>
                                <span class="status status--muted">Inactive</span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <section class="panel panel--danger no-print" aria-labelledby="danger-title">
            <header class="panel__header"><h3 id="danger-title">Delete</h3></header>
            <p class="field-help">Deleting is permanent. Categories that are in use are protected.</p>
            <form method="post" action="<?= e(url('pages/categories/delete.php')) ?>"
                  data-confirm="Are you sure you want to delete the category &quot;<?= e($category['name']) ?>&quot;? This cannot be undone.">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= e($category['id']) ?>">
                <input type="hidden" name="action" value="delete">
                <button type="submit" class="btn btn--danger btn--block"><?= icon('trash') ?> <span>Delete category</span></button>
            </form>
        </section>
    </div>
</div>

<?php require APP_ROOT . '/includes/footer.php'; ?>
