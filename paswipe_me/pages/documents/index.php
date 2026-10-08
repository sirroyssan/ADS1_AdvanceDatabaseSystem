<?php
/**
 * Document Files - list, search, filter by type/state/date, print.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/document_functions.php';

$currentUser = require_admin();

$state = list_state([
    'path'    => 'pages/documents/index.php',
    'title'   => 'Document Files',
    'search'  => 'Search file name…',
    'filters' => [
        'type'  => ['label' => 'Type', 'type' => 'select', 'options' => enum_options(ENUM_DOCUMENT_TYPE), 'all' => 'All types'],
        'state' => ['label' => 'State', 'type' => 'select', 'options' => ['archived' => 'Archived only', 'all' => 'Active and archived'], 'all' => 'Active files'],
        'from'  => ['label' => 'Uploaded from', 'type' => 'date'],
        'to'    => ['label' => 'Uploaded to', 'type' => 'date'],
    ],
    'sorts'   => [
        'newest' => ['Newest first', 'd.uploaded_at DESC'],
        'name'   => ['Name (A–Z)', 'd.original_filename ASC'],
        'size'   => ['Largest first', 'd.file_size DESC'],
    ],
]);

$where = [];
$params = [];
add_search_condition($state, ['d.original_filename'], $where, $params);
if ($state['filters']['type'] !== '') {
    $where[] = 'd.document_type = :type';
    $params[':type'] = $state['filters']['type'];
}
if ($state['filters']['state'] === 'archived') {
    $where[] = 'd.is_deleted = 1';
} elseif ($state['filters']['state'] === '') {
    $where[] = 'd.is_deleted = 0';
}
if ($state['filters']['from'] !== '') {
    $where[] = 'd.uploaded_at >= :from';
    $params[':from'] = $state['filters']['from'] . ' 00:00:00';
}
if ($state['filters']['to'] !== '') {
    $where[] = 'd.uploaded_at <= :to';
    $params[':to'] = $state['filters']['to'] . ' 23:59:59';
}

$result = run_list(
    $state,
    "d.id, d.document_type, d.original_filename, d.mime_type, d.file_size, d.uploaded_at, d.is_deleted,
     COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), ''), u.email) AS uploaded_by_name",
    'documents d LEFT JOIN users u ON u.id = d.uploaded_by',
    $where,
    $params
);

$pageTitle = 'Document Files';
$activeNav = 'documents';
require APP_ROOT . '/includes/header.php';
?>

<?= render_print_header($state, $result['total'], $result['pager']) ?>
<?= render_list_header($state, 'Receipts, statements, proofs of payment and signed agreements.', url('pages/documents/create.php'), 'Upload File') ?>
<?= render_list_toolbar($state) ?>

<section class="panel panel--flush" aria-label="Document list">
    <?php if ($result['total'] === 0): ?>
        <?= render_empty_state($state, url('pages/documents/create.php'), 'Upload receipts, statements and other proofs.', 'folder') ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table--cards">
                <thead>
                <tr>
                    <th scope="col" class="col-num">#</th>
                    <th scope="col">File</th>
                    <th scope="col">Type</th>
                    <th scope="col" class="num">Size</th>
                    <th scope="col">Uploaded</th>
                    <th scope="col">State</th>
                    <th scope="col" class="actions no-print"><span class="visually-hidden">Actions</span></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($result['rows'] as $i => $row): ?>
                    <tr>
                        <td class="col-num" data-label="#"><?= e($result['pager']['offset'] + $i + 1) ?></td>
                        <td data-label="File"><a class="table-link" href="<?= e(url('pages/documents/view.php', ['id' => $row['id']])) ?>"><?= e($row['original_filename']) ?></a></td>
                        <td data-label="Type"><?= e(humanize($row['document_type'])) ?></td>
                        <td data-label="Size" class="num"><?= e(format_bytes($row['file_size'] !== null ? (int) $row['file_size'] : null)) ?></td>
                        <td data-label="Uploaded"><?= e(format_datetime($row['uploaded_at'])) ?><?= $row['uploaded_by_name'] ? '<br><span class="text-muted">' . e($row['uploaded_by_name']) . '</span>' : '' ?></td>
                        <td data-label="State"><?= (int) $row['is_deleted'] === 1 ? badge('inactive') : badge('active') ?></td>
                        <td class="actions no-print">
                            <div class="row-actions">
                                <?= icon_link(url('pages/documents/view.php', ['id' => $row['id']]), 'eye', 'View ' . $row['original_filename']) ?>
                                <?= icon_link(url('pages/documents/download.php', ['id' => $row['id']]), 'arrow-left', 'Download ' . $row['original_filename']) ?>
                                <?= icon_link(url('pages/documents/edit.php', ['id' => $row['id']]), 'edit', 'Edit ' . $row['original_filename']) ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= render_list_footer($state, $result) ?>
    <?php endif; ?>
</section>

<?php require APP_ROOT . '/includes/footer.php'; ?>
