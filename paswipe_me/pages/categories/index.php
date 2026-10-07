<?php
/**
 * Categories - List, search, filter, paginate and print.
 *
 * GET parameters (all optional, all validated):
 *   search   - matches name, description and parent name
 *   status   - all | active | inactive
 *   parent   - '' (any) | top (top-level only) | <parent uuid>
 *   sort     - name_asc | name_desc | newest | oldest
 *   per_page - 10 | 25 | 50
 *   page     - page number
 *   print=1  - print view: same filters, every matching row, no pagination
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/category_functions.php';

$currentUser = require_admin();

// ---- Read and whitelist the filters ----------------------------------
$search  = mb_substr(input_string($_GET, 'search'), 0, 100);
$status  = input_string($_GET, 'status', 'all');
$parent  = input_string($_GET, 'parent');
$sort    = input_string($_GET, 'sort', 'name_asc');
$perPage = input_int($_GET, 'per_page', 10);
$page    = input_int($_GET, 'page', 1);
$isPrint = input_string($_GET, 'print') === '1';

$statusOptions  = ['all' => 'All statuses', 'active' => 'Active', 'inactive' => 'Inactive'];
$sortOptions    = [
    'name_asc'  => ['label' => 'Name (A–Z)',   'sql' => 'c.name ASC'],
    'name_desc' => ['label' => 'Name (Z–A)',   'sql' => 'c.name DESC'],
    'newest'    => ['label' => 'Newest first', 'sql' => 'c.created_at DESC, c.name ASC'],
    'oldest'    => ['label' => 'Oldest first', 'sql' => 'c.created_at ASC, c.name ASC'],
];
$perPageOptions = [10, 25, 50];

$status  = array_key_exists($status, $statusOptions) ? $status : 'all';
$sort    = array_key_exists($sort, $sortOptions) ? $sort : 'name_asc';
$perPage = in_array($perPage, $perPageOptions, true) ? $perPage : 10;
if ($parent !== '' && $parent !== 'top' && !is_valid_uuid($parent)) {
    $parent = '';
}

// Categories that have sub-categories, for the "Parent" filter.
$map = category_all_map();
$parentFilterOptions = [];
foreach ($map as $row) {
    if ($row['parent_id'] !== null && isset($map[$row['parent_id']])) {
        $parentFilterOptions[$row['parent_id']] = category_path($row['parent_id'], $map);
    }
}
asort($parentFilterOptions, SORT_NATURAL | SORT_FLAG_CASE);

// ---- Build the WHERE clause with placeholders only -------------------
$where  = [];
$params = [];

if ($search !== '') {
    $where[] = '(c.name LIKE :s_name OR c.description LIKE :s_desc OR p.name LIKE :s_parent)';
    $params[':s_name']   = like_value($search);
    $params[':s_desc']   = like_value($search);
    $params[':s_parent'] = like_value($search);
}
if ($status === 'active') {
    $where[] = 'c.is_active = 1';
} elseif ($status === 'inactive') {
    $where[] = 'c.is_active = 0';
}
if ($parent === 'top') {
    $where[] = 'c.parent_id IS NULL';
} elseif ($parent !== '') {
    $where[] = 'c.parent_id = :parent_id';
    $params[':parent_id'] = $parent;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$fromSql  = 'FROM categories c LEFT JOIN categories p ON p.id = c.parent_id ' . $whereSql;

// ---- Count, then fetch one page (or everything when printing) --------
$countStmt = db()->prepare('SELECT COUNT(*) ' . $fromSql);
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();

$grandTotal = (int) db()->query('SELECT COUNT(*) FROM categories')->fetchColumn();

const PRINT_LIMIT = 5000;

if ($isPrint) {
    $pager = paginate($total, 1, max(1, min($total, PRINT_LIMIT)));
    $limitSql = 'LIMIT ' . PRINT_LIMIT;
} else {
    $pager = paginate($total, $page, $perPage);
    $limitSql = 'LIMIT ' . (int) $pager['per_page'] . ' OFFSET ' . (int) $pager['offset'];
}

$listStmt = db()->prepare(
    'SELECT c.id, c.name, c.description, c.is_active, c.created_at, c.parent_id,
            p.name AS parent_name,
            (SELECT COUNT(*) FROM categories ch WHERE ch.parent_id = c.id) AS children_count,
            (SELECT COUNT(*) FROM merchants m WHERE m.category_id = c.id) AS merchant_count,
            (SELECT COUNT(*) FROM transactions t WHERE t.category_id = c.id) AS transaction_count
     ' . $fromSql . '
     ORDER BY ' . $sortOptions[$sort]['sql'] . '
     ' . $limitSql
);
$listStmt->execute($params);
$categories = $listStmt->fetchAll();

// ---- Helpers for links that keep the current filters ------------------
$filters = array_filter([
    'search'   => $search,
    'status'   => $status !== 'all' ? $status : '',
    'parent'   => $parent,
    'sort'     => $sort !== 'name_asc' ? $sort : '',
    'per_page' => $perPage !== 10 ? (string) $perPage : '',
], static fn ($v) => $v !== '');

$listUrl = static fn (array $extra = []): string => url('pages/categories/index.php', array_merge($filters, $extra));

$hasFilters = $search !== '' || $status !== 'all' || $parent !== '';

// Description of the active filters for the printed report.
$filterSummary = [];
if ($search !== '') {
    $filterSummary[] = 'Search: “' . $search . '”';
}
if ($status !== 'all') {
    $filterSummary[] = 'Status: ' . $statusOptions[$status];
}
if ($parent === 'top') {
    $filterSummary[] = 'Parent: top-level only';
} elseif ($parent !== '') {
    $filterSummary[] = 'Parent: ' . ($parentFilterOptions[$parent] ?? category_path($parent, $map));
}

$pageTitle   = 'Categories';
$activeNav   = 'categories';
$pageStyles  = ['assets/css/categories.css'];
$pageScripts = ['assets/js/categories.js'];
require APP_ROOT . '/includes/header.php';
?>

<?php if ($isPrint): ?>
    <div class="print-toolbar no-print" role="region" aria-label="Print preview">
        <p><?= icon('printer') ?> <span>Print preview — <?= e(format_number($total)) ?> record(s) with the current filters.</span></p>
        <div>
            <a href="<?= e($listUrl()) ?>" class="btn btn--outline btn--sm"><?= icon('arrow-left') ?> <span>Back to list</span></a>
            <button type="button" class="btn btn--primary btn--sm" data-print><?= icon('printer') ?> <span>Print</span></button>
        </div>
    </div>
<?php endif; ?>

<div class="print-header<?= $isPrint ? ' print-header--visible' : '' ?>" <?= $isPrint ? 'data-auto-print' : '' ?>>
    <p class="print-header__system"><?= e(APP_NAME) ?></p>
    <h2 class="print-header__title">Categories Report</h2>
    <p class="print-header__meta">Printed: <?= e(date('F j, Y g:i A')) ?> · Printed by: <?= e(user_display_name($currentUser)) ?></p>
    <p class="print-header__meta">
        <?= $filterSummary ? e(implode(' · ', $filterSummary)) : 'All categories' ?>
        · <?= e(format_number($total)) ?> record(s)
        <?php if (!$isPrint && $pager['pages'] > 1): ?>
            · Page <?= e($pager['page']) ?> of <?= e($pager['pages']) ?>
        <?php endif; ?>
    </p>
</div>

<?php if (!$isPrint): ?>
<section class="page-header no-print">
    <div>
        <h2 class="page-header__title">Categories</h2>
        <p class="page-header__subtitle">Categories and sub-categories used to classify merchants and transactions.</p>
    </div>
    <div class="page-header__actions">
        <a href="<?= e($listUrl(['print' => '1'])) ?>" class="btn btn--outline" title="Print all records that match the current filters">
            <?= icon('printer') ?> <span>Print</span>
        </a>
        <a href="<?= e(url('pages/categories/create.php')) ?>" class="btn btn--primary">
            <?= icon('plus') ?> <span>Add New</span>
        </a>
    </div>
</section>

<form method="get" action="<?= e(url('pages/categories/index.php')) ?>" class="toolbar no-print" role="search" id="category-filters">
    <div class="toolbar__search">
        <label for="search" class="visually-hidden">Search categories</label>
        <div class="input-icon">
            <?= icon('search') ?>
            <input type="search" id="search" name="search" value="<?= e($search) ?>" maxlength="100"
                   placeholder="Search name, description or parent…">
        </div>
    </div>

    <div class="toolbar__filters">
        <label class="visually-hidden" for="status">Status</label>
        <select id="status" name="status" data-auto-submit>
            <?php foreach ($statusOptions as $value => $label): ?>
                <option value="<?= e($value) ?>" <?= $value === $status ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>

        <label class="visually-hidden" for="parent">Parent category</label>
        <select id="parent" name="parent" data-auto-submit>
            <option value="">Any parent</option>
            <option value="top" <?= $parent === 'top' ? 'selected' : '' ?>>Top-level only</option>
            <?php foreach ($parentFilterOptions as $value => $label): ?>
                <option value="<?= e($value) ?>" <?= $value === $parent ? 'selected' : '' ?>>Under: <?= e($label) ?></option>
            <?php endforeach; ?>
        </select>

        <label class="visually-hidden" for="sort">Sort by</label>
        <select id="sort" name="sort" data-auto-submit>
            <?php foreach ($sortOptions as $value => $option): ?>
                <option value="<?= e($value) ?>" <?= $value === $sort ? 'selected' : '' ?>><?= e($option['label']) ?></option>
            <?php endforeach; ?>
        </select>

        <?php if ($perPage !== 10): ?>
            <input type="hidden" name="per_page" value="<?= e($perPage) ?>">
        <?php endif; ?>

        <button type="submit" class="btn btn--primary" data-loading-text="Searching…">
            <?= icon('search') ?> <span class="btn__label">Search</span>
        </button>
        <?php if ($hasFilters): ?>
            <a href="<?= e(url('pages/categories/index.php')) ?>" class="btn btn--ghost"><?= icon('x') ?> <span>Clear</span></a>
        <?php endif; ?>
    </div>
</form>
<?php endif; ?>

<section class="panel panel--flush" aria-label="Category list">
    <?php if ($total === 0): ?>
        <div class="empty-state">
            <div class="empty-state__icon"><?= icon($hasFilters ? 'search' : 'tag', 'icon icon--xl') ?></div>
            <?php if ($hasFilters): ?>
                <h3>No matching records found.</h3>
                <p class="text-muted">Try another search term or clear the filters.</p>
                <a href="<?= e(url('pages/categories/index.php')) ?>" class="btn btn--outline no-print"><?= icon('x') ?> <span>Clear Search</span></a>
            <?php else: ?>
                <h3>No records available.</h3>
                <p class="text-muted">Create the first category to start classifying merchants and transactions.</p>
                <a href="<?= e(url('pages/categories/create.php')) ?>" class="btn btn--primary no-print"><?= icon('plus') ?> <span>Add New</span></a>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table--cards" id="category-table">
                <thead>
                <tr>
                    <th scope="col" class="col-num">#</th>
                    <th scope="col">Name</th>
                    <th scope="col">Parent</th>
                    <th scope="col">Description</th>
                    <th scope="col">Status</th>
                    <th scope="col" class="num">Used by</th>
                    <th scope="col">Created</th>
                    <th scope="col" class="actions no-print"><span class="visually-hidden">Actions</span></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($categories as $index => $row): ?>
                    <?php
                    $usageTotal = (int) $row['children_count'] + (int) $row['merchant_count'] + (int) $row['transaction_count'];
                    $viewUrl = url('pages/categories/view.php', ['id' => $row['id']]);
                    ?>
                    <tr>
                        <td class="col-num" data-label="#"><?= e($pager['offset'] + $index + 1) ?></td>
                        <td data-label="Name">
                            <a href="<?= e($viewUrl) ?>" class="table-link"><?= e($row['name']) ?></a>
                            <?php if ((int) $row['children_count'] > 0): ?>
                                <span class="chip chip--muted"><?= e($row['children_count']) ?> sub</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Parent"><?= $row['parent_name'] === null ? '<span class="text-muted">—</span>' : e($row['parent_name']) ?></td>
                        <td data-label="Description" class="cell-description">
                            <?= $row['description'] === null ? '<span class="text-muted">—</span>' : e(excerpt($row['description'], 70)) ?>
                        </td>
                        <td data-label="Status">
                            <?php if ((int) $row['is_active'] === 1): ?>
                                <span class="status status--success"><?= icon('check', 'icon icon--sm') ?> Active</span>
                            <?php else: ?>
                                <span class="status status--muted"><?= icon('x', 'icon icon--sm') ?> Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Used by" class="num"
                            title="<?= e($row['merchant_count'] . ' merchant(s), ' . $row['transaction_count'] . ' transaction(s), ' . $row['children_count'] . ' sub-categories') ?>">
                            <?= e($usageTotal) ?>
                        </td>
                        <td data-label="Created"><?= e(format_date($row['created_at'])) ?></td>
                        <td class="actions no-print">
                            <div class="row-actions">
                                <a href="<?= e($viewUrl) ?>" class="icon-button" title="View" aria-label="View <?= e($row['name']) ?>"><?= icon('eye') ?></a>
                                <a href="<?= e(url('pages/categories/edit.php', ['id' => $row['id']])) ?>" class="icon-button" title="Edit" aria-label="Edit <?= e($row['name']) ?>"><?= icon('edit') ?></a>
                                <?php if ($usageTotal > 0): ?>
                                    <a href="<?= e(url('pages/categories/delete.php', ['id' => $row['id']])) ?>" class="icon-button icon-button--danger"
                                       title="In use – delete options" aria-label="Delete options for <?= e($row['name']) ?>"><?= icon('trash') ?></a>
                                <?php else: ?>
                                    <form method="post" action="<?= e(url('pages/categories/delete.php')) ?>" class="inline-form"
                                          data-confirm="Are you sure you want to delete the category &quot;<?= e($row['name']) ?>&quot;? This cannot be undone.">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= e($row['id']) ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <button type="submit" class="icon-button icon-button--danger" title="Delete" aria-label="Delete <?= e($row['name']) ?>"><?= icon('trash') ?></button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($isPrint && $total > PRINT_LIMIT): ?>
            <p class="table-note">Only the first <?= e(format_number(PRINT_LIMIT)) ?> records are printed. Narrow the filters to print the rest.</p>
        <?php endif; ?>

        <?php if (!$isPrint): ?>
            <footer class="table-footer no-print">
                <p class="table-footer__info">
                    Showing <strong><?= e($pager['from']) ?>–<?= e($pager['to']) ?></strong> of
                    <strong><?= e(format_number($total)) ?></strong> record(s)
                    <?php if ($hasFilters): ?>
                        <span class="text-muted">(filtered from <?= e(format_number($grandTotal)) ?>)</span>
                    <?php endif; ?>
                </p>

                <form method="get" action="<?= e(url('pages/categories/index.php')) ?>" class="per-page">
                    <?php foreach ($filters as $key => $value): ?>
                        <?php if ($key !== 'per_page'): ?>
                            <input type="hidden" name="<?= e($key) ?>" value="<?= e($value) ?>">
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <label for="per_page">Rows</label>
                    <select id="per_page" name="per_page" data-auto-submit>
                        <?php foreach ($perPageOptions as $option): ?>
                            <option value="<?= e($option) ?>" <?= $option === $perPage ? 'selected' : '' ?>><?= e($option) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <noscript><button type="submit" class="btn btn--sm btn--outline">Apply</button></noscript>
                </form>

                <?php if ($pager['pages'] > 1): ?>
                    <nav class="pagination" aria-label="Pagination">
                        <?php if ($pager['page'] > 1): ?>
                            <a href="<?= e($listUrl(['page' => $pager['page'] - 1])) ?>" class="pagination__link" rel="prev">Previous</a>
                        <?php else: ?>
                            <span class="pagination__link is-disabled" aria-disabled="true">Previous</span>
                        <?php endif; ?>

                        <?php foreach (pagination_links($pager['page'], $pager['pages']) as $number): ?>
                            <?php if ($number === null): ?>
                                <span class="pagination__gap" aria-hidden="true">…</span>
                            <?php elseif ($number === $pager['page']): ?>
                                <span class="pagination__link is-current" aria-current="page"><?= e($number) ?></span>
                            <?php else: ?>
                                <a href="<?= e($listUrl(['page' => $number])) ?>" class="pagination__link"><?= e($number) ?></a>
                            <?php endif; ?>
                        <?php endforeach; ?>

                        <?php if ($pager['page'] < $pager['pages']): ?>
                            <a href="<?= e($listUrl(['page' => $pager['page'] + 1])) ?>" class="pagination__link" rel="next">Next</a>
                        <?php else: ?>
                            <span class="pagination__link is-disabled" aria-disabled="true">Next</span>
                        <?php endif; ?>
                    </nav>
                <?php endif; ?>
            </footer>
        <?php endif; ?>
    <?php endif; ?>
</section>

<?php if ($isPrint): ?>
    <p class="print-footer">— End of report —</p>
<?php endif; ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
