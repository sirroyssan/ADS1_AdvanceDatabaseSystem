<?php
/**
 * Authorization Requests: the owner sees every request on their books (pending
 * first) and decides; staff see the requests they filed.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once APP_ROOT . '/includes/authorizations.php';

$currentUser = require_staff_area();

$state = list_state([
    'path'    => 'pages/authorizations/index.php',
    'title'   => 'Authorization Requests',
    'search'  => 'Search request no., record or reason…',
    'filters' => [
        'status' => ['label' => 'Status', 'type' => 'select', 'options' => ['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected'], 'all' => 'All statuses'],
        'type'   => ['label' => 'Record', 'type' => 'select', 'options' => AUTH_RECORD_TYPES, 'all' => 'All records'],
    ],
    'sorts'   => ['pending' => ['Pending first', "r.status = 'pending' DESC, r.created_at DESC"], 'newest' => ['Newest first', 'r.created_at DESC']],
]);
$where = [owner_scope('authorization_requests', 'r')];
$params = [];
if (user_is_staff($currentUser)) {
    $where[] = 'r.requested_by = :me';
    $params[':me'] = $currentUser['id'];
}
add_search_condition($state, ['r.request_number', 'r.record_ref', 'r.reason'], $where, $params);
foreach (['status' => 'r.status', 'type' => 'r.record_type'] as $k => $col) {
    if ($state['filters'][$k] !== '') {
        $where[] = "$col = :$k";
        $params[":$k"] = $state['filters'][$k];
    }
}
$result = run_list($state, 'r.*', 'authorization_requests r', $where, $params);

$pageTitle = 'Authorization Requests';
$activeNav = 'authorizations';
require APP_ROOT . '/includes/header.php';
?>

<?= render_print_header($state, $result['total'], $result['pager']) ?>
<?= render_list_header($state, user_is_staff($currentUser)
    ? 'Changes you asked the card owner to approve. Nothing changes until they approve.'
    : 'Edit and delete requests from your staff. Approving applies the change at once; every decision is kept in the audit log.', null) ?>
<?= render_list_toolbar($state) ?>

<section class="panel panel--flush" aria-label="Requests">
    <?php if ($result['total'] === 0): ?>
        <?= render_empty_state($state, null, 'No change requests.', 'shield') ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table--cards">
                <thead><tr><th scope="col">Request</th><th scope="col">Record</th><th scope="col">Action</th><th scope="col">Requested by</th><th scope="col">Reason</th><th scope="col">Status</th><th scope="col">Decided</th></tr></thead>
                <tbody>
                <?php foreach ($result['rows'] as $q): ?>
                    <tr>
                        <td data-label="Request"><a data-modal-form class="table-link" href="<?= e(url('pages/authorizations/view.php', ['id' => $q['id']])) ?>"><?= e($q['request_number']) ?></a><br><span class="text-muted"><?= e(format_datetime($q['created_at'])) ?></span></td>
                        <td data-label="Record"><?= e(AUTH_RECORD_TYPES[$q['record_type']] ?? $q['record_type']) ?><br><span class="text-muted"><?= e($q['record_ref']) ?></span></td>
                        <td data-label="Action"><?= e(AUTH_ACTIONS[$q['action']] ?? $q['action']) ?></td>
                        <td data-label="Requested by"><?= e(user_name_of($q['requested_by'])) ?></td>
                        <td data-label="Reason"><?= e(excerpt($q['reason'], 60)) ?></td>
                        <td data-label="Status"><?= auth_status_badge($q['status']) ?></td>
                        <td data-label="Decided"><?= $q['decided_at'] ? e(user_name_of($q['decided_by'])) . '<br><span class="text-muted">' . e(format_datetime($q['decided_at'])) . '</span>' : '—' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= render_list_footer($state, $result) ?>
    <?php endif; ?>
</section>

<?php require APP_ROOT . '/includes/footer.php'; ?>
