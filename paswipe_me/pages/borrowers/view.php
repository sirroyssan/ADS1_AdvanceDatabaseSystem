<?php
/**
 * Borrowers - borrower profile: details, balances, addresses, contacts,
 * linked sign-in account, obligations, payments and payment methods.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/borrower_functions.php';

$currentUser = require_admin();

$borrower = find_or_redirect(borrower_find(request_id()), 'pages/borrowers/index.php', 'Borrower');
$id = $borrower['id'];

$balance = db_row('SELECT total_charged, total_paid, outstanding, overdue FROM vw_borrower_balances WHERE borrower_id = :id', [':id' => $id])
    ?? ['total_charged' => 0, 'total_paid' => 0, 'outstanding' => 0, 'overdue' => 0];
$addresses = db_all('SELECT * FROM borrower_addresses WHERE borrower_id = :id ORDER BY is_primary DESC, created_at', [':id' => $id]);
$contacts = db_all('SELECT * FROM borrower_contacts WHERE borrower_id = :id ORDER BY created_at', [':id' => $id]);
$account = db_row(
    'SELECT a.id, a.activated_at, a.deactivated_at, u.id AS user_id, u.email, u.username, u.status
       FROM borrower_user_accounts a JOIN users u ON u.id = a.user_id WHERE a.borrower_id = :id',
    [':id' => $id]
);
$obligations = db_all(
    'SELECT o.id, o.obligation_number, o.obligation_date, o.due_date, o.total_amount_due, o.outstanding_amount, o.status,
            COALESCE(pen.penalties_unpaid, 0) AS penalties_unpaid
       FROM borrower_obligations o ' . penalty_totals_join() . ' WHERE o.borrower_id = :id ORDER BY o.obligation_date DESC LIMIT 10',
    [':id' => $id]
);
$collect = collection_totals($id);
$payments = db_all(
    'SELECT id, payment_number, payment_date, amount, status FROM borrower_payments WHERE borrower_id = :id ORDER BY payment_date DESC LIMIT 10',
    [':id' => $id]
);
$methods = db_all('SELECT id, display_name, method_type, is_active FROM payment_methods WHERE borrower_id = :id ORDER BY display_name', [':id' => $id]);
$usage = borrower_usage($id);
$linkable = $account ? [] : linkable_borrower_users();

$related = url('pages/borrowers/related.php');

$pageTitle = 'Borrower Details';
$activeNav = 'borrowers';
require APP_ROOT . '/includes/header.php';
?>

<?= render_breadcrumb(['Borrowers' => url('pages/borrowers/index.php'), borrower_full_name($borrower) => null]) ?>

<div class="print-header">
    <p class="print-header__system"><?= e(APP_NAME) ?></p>
    <h2 class="print-header__title">Borrower Profile</h2>
    <p class="print-header__meta">Printed: <?= e(date('F j, Y g:i A')) ?></p>
</div>

<section class="page-header">
    <div>
        <h2 class="page-header__title"><?= e(borrower_full_name($borrower)) ?></h2>
        <p class="page-header__subtitle"><?= e($borrower['borrower_number']) ?> · <?= badge($borrower['status']) ?></p>
    </div>
    <div class="page-header__actions no-print">
        <a href="<?= e(url('pages/borrowers/index.php')) ?>" class="btn btn--outline"><?= icon('arrow-left') ?> <span>Back</span></a>
        <button type="button" class="btn btn--outline" data-print><?= icon('printer') ?> <span>Print</span></button>
        <a href="<?= e(url('pages/borrowers/edit.php', ['id' => $id])) ?>" class="btn btn--primary"><?= icon('edit') ?> <span>Edit</span></a>
    </div>
</section>

<section class="figure-grid" aria-label="Balances">
    <div class="figure"><p class="figure__label">Total charged</p><p class="figure__value"><?= e(format_money($balance['total_charged'])) ?></p></div>
    <div class="figure"><p class="figure__label">Total paid</p><p class="figure__value"><?= e(format_money($balance['total_paid'])) ?></p></div>
    <div class="figure"><p class="figure__label">Outstanding</p><p class="figure__value"><?= e(format_money($balance['outstanding'])) ?></p></div>
    <div class="figure"><p class="figure__label">Overdue</p><p class="figure__value<?= (float) $balance['overdue'] > 0 ? ' text-danger' : '' ?>"><?= e(format_money($balance['overdue'])) ?></p></div>
    <div class="figure"><p class="figure__label">Unpaid penalties</p><p class="figure__value<?= (float) $collect['penalties'] > 0 ? ' text-danger' : '' ?>"><?= e(format_money($collect['penalties'])) ?></p></div>
    <div class="figure figure--total"><p class="figure__label">Total to pay</p><p class="figure__value"><?= e(format_money($collect['total_due'])) ?></p>
        <p class="figure__note text-muted">Outstanding + penalties</p></div>
</section>

<div class="detail-layout">
    <section class="panel">
        <header class="panel__header"><h3>Record details</h3></header>
        <?= render_details([
            'Borrower number'  => e($borrower['borrower_number']),
            'Full name'        => e(borrower_full_name($borrower)),
            'Nickname'         => show($borrower['nickname']),
            'Relationship'     => $borrower['relationship']
                ? e(RELATIONSHIP_FAMILY[$borrower['relationship']] ?? RELATIONSHIP_OTHERS[$borrower['relationship']] ?? $borrower['relationship'])
                  . ' <span class="text-muted">(' . (relationship_is_family($borrower['relationship']) ? 'family — proof of payment optional' : 'not related — proof of payment required') . ')</span>'
                : '<span class="text-muted">Not set — proof of payment required</span>',
            'Primary mobile'   => show($borrower['primary_mobile']),
            'Primary email'    => show($borrower['primary_email']),
            'Status'           => badge($borrower['status']),
            'Notes'            => show_multiline($borrower['notes']),
            'Created'          => e(format_datetime($borrower['created_at'])) . ($borrower['created_by_name'] ? ' by ' . e($borrower['created_by_name']) : ''),
            'Last updated'     => e(format_datetime($borrower['updated_at'])) . ($borrower['updated_by_name'] ? ' by ' . e($borrower['updated_by_name']) : ''),
        ]) ?>
    </section>

    <div class="detail-side">
        <section class="panel">
            <header class="panel__header"><h3>Sign-in account</h3></header>
            <?php if ($account): ?>
                <p><?= related_link($account['user_id'], $account['email'], 'pages/users/view.php') ?> <?= badge($account['status']) ?></p>
                <p class="field-help">Linked <?= e(format_datetime($account['activated_at'])) ?></p>
                <div class="action-bar no-print">
                    <?= post_button($related, ['id' => $id, 'action' => 'unlink_account', 'item_id' => $account['id']], 'Unlink account', 'x', 'btn btn--outline btn--sm',
                        'Unlink this sign-in account from the borrower? The user account itself is kept.', false, 'Unlink account?') ?>
                </div>
            <?php elseif ($linkable): ?>
                <form method="post" action="<?= e($related) ?>" class="status-form no-print">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= e($id) ?>">
                    <input type="hidden" name="action" value="link_account">
                    <div class="form-group">
                        <label for="link_user">User with Borrower role</label>
                        <select id="link_user" name="user_id" required>
                            <option value="">Select account</option>
                            <?php foreach ($linkable as $userId => $label): ?>
                                <option value="<?= e($userId) ?>"><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn--primary" data-loading-text="Linking…"><?= icon('check') ?> <span class="btn__label">Link</span></button>
                </form>
            <?php else: ?>
                <p class="field-help">No sign-in account. Create a user with the Borrower role in <a href="<?= e(url('pages/users/create.php')) ?>">Users &amp; Roles</a>, then link it here.</p>
            <?php endif; ?>
        </section>

        <section class="panel">
            <header class="panel__header">
                <h3>Payment methods</h3>
                <a class="btn btn--sm btn--outline no-print" href="<?= e(url('pages/payment_methods/create.php', ['borrower_id' => $id])) ?>"><?= icon('plus') ?> <span>Add</span></a>
            </header>
            <?php if (!$methods): ?>
                <p class="empty-inline">No payment methods.</p>
            <?php else: ?>
                <ul class="child-list">
                    <?php foreach ($methods as $m): ?>
                        <li><a href="<?= e(url('pages/payment_methods/view.php', ['id' => $m['id']])) ?>"><?= e($m['display_name']) ?></a>
                            <span><?= e(strtoupper(humanize($m['method_type']))) ?> <?= (int) $m['is_active'] === 1 ? '' : badge('inactive') ?></span></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <section class="panel panel--danger no-print">
            <header class="panel__header"><h3>Delete or deactivate</h3></header>
            <?php if ($usage['total'] > 0): ?>
                <p class="field-help">This borrower has <?= e(usage_text($usage)) ?>, so the record must be kept.<?= $borrower['status'] !== 'inactive' ? ' Set the status to inactive instead.' : '' ?></p>
                <?php if ($borrower['status'] !== 'inactive'): ?>
                    <?= post_button(url('pages/borrowers/delete.php'), ['id' => $id, 'action' => 'deactivate'], 'Mark as inactive', 'power', 'btn btn--warning btn--block',
                        'Mark this borrower as inactive? Their history is kept.', false, 'Deactivate borrower?') ?>
                <?php endif; ?>
            <?php else: ?>
                <p class="field-help">No financial records yet. Deleting also removes the addresses and contacts.</p>
                <?= post_button(url('pages/borrowers/delete.php'), ['id' => $id, 'action' => 'delete'], 'Delete borrower', 'trash', 'btn btn--danger btn--block',
                    'Are you sure you want to delete this borrower with their addresses and contacts? This cannot be undone.') ?>
            <?php endif; ?>
        </section>
    </div>
</div>

<section class="panel subpanel">
    <header class="panel__header">
        <h3>Addresses</h3>
        <button type="button" class="btn btn--sm btn--outline no-print" data-toggle-panel="add-address"><?= icon('plus') ?> <span>Add address</span></button>
    </header>
    <form method="post" action="<?= e($related) ?>" class="inline-panel no-print" id="add-address" hidden novalidate data-validate>
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= e($id) ?>">
        <input type="hidden" name="action" value="add_address">
        <?= render_fields(address_fields(), form_defaults(address_fields(), ['is_primary' => $addresses ? 0 : 1]), []) ?>
        <div class="inline-panel__actions">
            <button type="button" class="btn btn--outline" data-toggle-panel="add-address">Cancel</button>
            <button type="submit" class="btn btn--primary" data-loading-text="Saving…"><?= icon('check') ?> <span class="btn__label">Save address</span></button>
        </div>
    </form>
    <?php if (!$addresses): ?>
        <p class="empty-inline">No addresses recorded.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table--cards table--compact">
                <thead><tr><th scope="col">Type</th><th scope="col">Address</th><th scope="col">Primary</th><th scope="col" class="actions no-print"><span class="visually-hidden">Actions</span></th></tr></thead>
                <tbody>
                <?php foreach ($addresses as $a): ?>
                    <tr>
                        <td data-label="Type"><?= e(humanize($a['address_type'])) ?></td>
                        <td data-label="Address"><?= show(format_address($a)) ?></td>
                        <td data-label="Primary"><?= (int) $a['is_primary'] === 1 ? '<span class="status status--success">Primary</span>' : '<span class="text-muted">—</span>' ?></td>
                        <td class="actions no-print"><div class="row-actions">
                            <?php if ((int) $a['is_primary'] !== 1): ?>
                                <?= post_button($related, ['id' => $id, 'action' => 'primary_address', 'item_id' => $a['id']], 'Make primary', 'check', 'icon-button', null, true) ?>
                            <?php endif; ?>
                            <?= post_button($related, ['id' => $id, 'action' => 'delete_address', 'item_id' => $a['id']], 'Delete address', 'trash', 'icon-button icon-button--danger',
                                'Delete this address?', true) ?>
                        </div></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="panel subpanel">
    <header class="panel__header">
        <h3>Contacts</h3>
        <button type="button" class="btn btn--sm btn--outline no-print" data-toggle-panel="add-contact"><?= icon('plus') ?> <span>Add contact</span></button>
    </header>
    <form method="post" action="<?= e($related) ?>" class="inline-panel no-print" id="add-contact" hidden novalidate data-validate>
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= e($id) ?>">
        <input type="hidden" name="action" value="add_contact">
        <?= render_fields(contact_fields(), [], []) ?>
        <div class="inline-panel__actions">
            <button type="button" class="btn btn--outline" data-toggle-panel="add-contact">Cancel</button>
            <button type="submit" class="btn btn--primary" data-loading-text="Saving…"><?= icon('check') ?> <span class="btn__label">Save contact</span></button>
        </div>
    </form>
    <?php if (!$contacts): ?>
        <p class="empty-inline">No contacts recorded.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table--cards table--compact">
                <thead><tr><th scope="col">Type</th><th scope="col">Name</th><th scope="col">Relationship</th><th scope="col">Mobile / Email</th><th scope="col">Notes</th><th scope="col" class="actions no-print"><span class="visually-hidden">Actions</span></th></tr></thead>
                <tbody>
                <?php foreach ($contacts as $c): ?>
                    <tr>
                        <td data-label="Type"><?= e(humanize($c['contact_type'])) ?></td>
                        <td data-label="Name"><?= show($c['contact_name']) ?></td>
                        <td data-label="Relationship"><?= show($c['relationship']) ?></td>
                        <td data-label="Mobile / Email"><?= show(trim(($c['mobile'] ?? '') . ' ' . ($c['email'] ?? ''))) ?></td>
                        <td data-label="Notes"><?= show(excerpt($c['notes'], 60)) ?></td>
                        <td class="actions no-print"><div class="row-actions">
                            <?= post_button($related, ['id' => $id, 'action' => 'delete_contact', 'item_id' => $c['id']], 'Delete contact', 'trash', 'icon-button icon-button--danger',
                                'Delete this contact?', true) ?>
                        </div></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<div class="panel-grid">
    <section class="panel subpanel">
        <header class="panel__header">
            <h3>Recent obligations</h3>
            <a class="btn btn--sm btn--outline no-print" href="<?= e(url('pages/obligations/create.php', ['borrower_id' => $id])) ?>"><?= icon('plus') ?> <span>Add</span></a>
        </header>
        <?php if (!$obligations): ?>
            <p class="empty-inline">No obligations.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table table--compact">
                    <thead><tr><th scope="col">Obligation #</th><th scope="col">Due</th><th scope="col" class="num">Outstanding</th><th scope="col" class="num">Penalties</th><th scope="col">Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($obligations as $o): ?>
                        <tr>
                            <td><a href="<?= e(url('pages/obligations/view.php', ['id' => $o['id']])) ?>"><?= e($o['obligation_number']) ?></a></td>
                            <td><?= e(format_date($o['due_date'])) ?></td>
                            <td class="num"><?= e(format_money($o['outstanding_amount'])) ?></td>
                            <td class="num<?= (float) $o['penalties_unpaid'] > 0 ? ' text-danger' : '' ?>"><?= e(format_money($o['penalties_unpaid'])) ?></td>
                            <td><?= badge($o['status']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="panel subpanel">
        <header class="panel__header">
            <h3>Recent payments</h3>
            <a class="btn btn--sm btn--outline no-print" href="<?= e(url('pages/borrower_payments/create.php', ['borrower_id' => $id])) ?>"><?= icon('plus') ?> <span>Record</span></a>
        </header>
        <?php if (!$payments): ?>
            <p class="empty-inline">No payments.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table table--compact">
                    <thead><tr><th scope="col">Payment #</th><th scope="col">Date</th><th scope="col" class="num">Amount</th><th scope="col">Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($payments as $p): ?>
                        <tr>
                            <td><a href="<?= e(url('pages/borrower_payments/view.php', ['id' => $p['id']])) ?>"><?= e($p['payment_number']) ?></a></td>
                            <td><?= e(format_date($p['payment_date'])) ?></td>
                            <td class="num"><?= e(format_money($p['amount'])) ?></td>
                            <td><?= badge($p['status']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>

<?php require APP_ROOT . '/includes/footer.php'; ?>
