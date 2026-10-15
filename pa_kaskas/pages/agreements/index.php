<?php
/**
 * Agreements - list, search, filter, print.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/agreement_functions.php';

$currentUser = require_admin();

$state = list_state([
    'path'    => 'pages/agreements/index.php',
    'title'   => 'Agreements',
    'search'  => 'Search agreement number, borrower or terms…',
    'filters' => [
        'status'   => ['label' => 'Status', 'type' => 'select', 'options' => enum_options(ENUM_AGREEMENT_STATUS), 'all' => 'All statuses'],
        'borrower' => ['label' => 'Borrower', 'type' => 'select', 'options' => borrower_options(null, false), 'all' => 'All borrowers'],
        'from'     => ['label' => 'From', 'type' => 'date'],
        'to'       => ['label' => 'To', 'type' => 'date'],
    ],
    'sorts'   => [
        'newest' => ['Newest first', 'a.agreement_date DESC, a.created_at DESC'],
        'oldest' => ['Oldest first', 'a.agreement_date ASC'],
        'number' => ['Agreement number', 'a.agreement_number ASC'],
    ],
]);

$where = [owner_scope('agreements', 'a')];
$params = [];
add_search_condition($state, ['a.agreement_number', 'a.payment_terms', 'b.first_name', 'b.last_name', 'b.borrower_number'], $where, $params);
foreach (['status' => 'a.status', 'borrower' => 'a.borrower_id'] as $key => $column) {
    if ($state['filters'][$key] !== '') {
        $where[] = $column . ' = :' . $key;
        $params[':' . $key] = $state['filters'][$key];
    }
}
if ($state['filters']['from'] !== '') {
    $where[] = 'a.agreement_date >= :from';
    $params[':from'] = $state['filters']['from'];
}
if ($state['filters']['to'] !== '') {
    $where[] = 'a.agreement_date <= :to';
    $params[':to'] = $state['filters']['to'];
}

$result = run_list(
    $state,
    "a.id, a.agreement_number, a.agreement_date, a.status, a.original_amount, a.installment_amount, a.installment_count,
     b.first_name, b.last_name, t.name AS template_name,
     (SELECT COUNT(*) FROM agreement_signatories s WHERE s.agreement_id = a.id) AS signers,
     (SELECT COUNT(*) FROM agreement_signatories s WHERE s.agreement_id = a.id AND s.status = 'signed') AS signed",
    'agreements a JOIN borrowers b ON b.id = a.borrower_id LEFT JOIN agreement_templates t ON t.id = a.template_id',
    $where,
    $params
);

$pageTitle = 'Agreements';
$activeNav = 'agreements';
require APP_ROOT . '/includes/header.php';
?>

<?= render_print_header($state, $result['total'], $result['pager']) ?>
<?= render_list_header($state, 'Repayment agreements with borrowers, their signatories and signed copies.', url('pages/agreements/create.php')) ?>
<?= render_list_toolbar($state) ?>

<section class="panel panel--flush" aria-label="Agreement list">
    <?php if ($result['total'] === 0): ?>
        <?= render_empty_state($state, url('pages/agreements/create.php'), 'Create an agreement for a borrower\'s obligation.', 'file-sign') ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table--cards">
                <thead>
                <tr>
                    <th scope="col" class="col-num">#</th>
                    <th scope="col">Agreement #</th>
                    <th scope="col">Date</th>
                    <th scope="col">Borrower</th>
                    <th scope="col">Template</th>
                    <th scope="col">Terms</th>
                    <th scope="col">Signatures</th>
                    <th scope="col">Status</th>
                    <th scope="col" class="actions no-print"><span class="visually-hidden">Actions</span></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($result['rows'] as $i => $row): ?>
                    <tr>
                        <td class="col-num" data-label="#"><?= e($result['pager']['offset'] + $i + 1) ?></td>
                        <td data-label="Agreement #"><a data-modal-form class="table-link" href="<?= e(url('pages/agreements/view.php', ['id' => $row['id']])) ?>"><?= e($row['agreement_number']) ?></a></td>
                        <td data-label="Date"><?= e(format_date($row['agreement_date'])) ?></td>
                        <td data-label="Borrower"><?= e(borrower_name($row)) ?></td>
                        <td data-label="Template"><?= show($row['template_name']) ?></td>
                        <td data-label="Terms"><?= $row['installment_count'] ? e($row['installment_count']) . ' × ' . e(format_money($row['installment_amount'])) : show_money($row['original_amount']) ?></td>
                        <td data-label="Signatures"><?= e($row['signed']) ?> / <?= e($row['signers']) ?></td>
                        <td data-label="Status"><?= badge($row['status']) ?></td>
                        <td class="actions no-print">
                            <div class="row-actions">
                                <?= icon_link(url('pages/agreements/view.php', ['id' => $row['id']]), 'eye', 'View ' . $row['agreement_number']) ?>
                                <?php if (!in_array($row['status'], AGREEMENT_LOCKED_STATUSES, true)): ?>
                                    <?= icon_link(url('pages/agreements/edit.php', ['id' => $row['id']]), 'edit', 'Edit ' . $row['agreement_number']) ?>
                                <?php endif; ?>
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
