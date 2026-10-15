<?php
/**
 * Borrowers - delete (no financial history only) or mark inactive.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/borrower_functions.php';

$currentUser = require_admin();

if (!is_post()) {
    redirect('pages/borrowers/index.php');
}

$borrower = find_or_redirect(borrower_find(request_id()), 'pages/borrowers/index.php', 'Borrower');
require_csrf('pages/borrowers/view.php?id=' . $borrower['id']);
$name = borrower_full_name($borrower);

if (input_string($_POST, 'action') === 'deactivate') {
    db_update('borrowers', ['status' => 'inactive', 'updated_by' => $currentUser['id']], $borrower['id']);
    log_activity('deactivate', 'borrower', $borrower['id'], 'Marked borrower ' . $borrower['borrower_number'] . ' inactive', ['status' => $borrower['status']], ['status' => 'inactive']);
    flash('success', $name . ' was marked as inactive.');
    redirect('pages/borrowers/view.php', ['id' => $borrower['id']]);
}

try {
    db_transaction(function () use ($borrower) {
        db_value('SELECT id FROM borrowers WHERE id = :id FOR UPDATE', [':id' => $borrower['id']]);
        $usage = borrower_usage($borrower['id']);
        if ($usage['total'] > 0) {
            throw new DomainException('This borrower cannot be deleted because they have ' . usage_text($usage) . '. Mark them inactive instead.');
        }
        // Addresses and contacts are removed by ON DELETE CASCADE; deleted explicitly too
        // in case the database was imported without foreign keys (MariaDB 10.4).
        db_exec('DELETE FROM borrower_addresses WHERE borrower_id = :id', [':id' => $borrower['id']]);
        db_exec('DELETE FROM borrower_contacts WHERE borrower_id = :id', [':id' => $borrower['id']]);
        db_exec('DELETE FROM borrowers WHERE id = :id', [':id' => $borrower['id']]);
    });

    log_activity('delete', 'borrower', $borrower['id'], 'Deleted borrower ' . $borrower['borrower_number'] . ' ' . $name, $borrower);
    flash('success', 'Borrower ' . $name . ' was deleted.');
    redirect('pages/borrowers/index.php');
} catch (DomainException $e) {
    flash('error', $e->getMessage());
} catch (PDOException $e) {
    flash('error', db_error_message($e));
}

redirect('pages/borrowers/view.php', ['id' => $borrower['id']]);
