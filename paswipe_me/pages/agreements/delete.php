<?php
/**
 * Agreements - delete a draft (signatories and document links are removed with it).
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/agreement_functions.php';

$currentUser = require_admin();

if (!is_post()) {
    redirect('pages/agreements/index.php');
}

$agreement = find_or_redirect(agreement_find(request_id()), 'pages/agreements/index.php', 'Agreement');
require_csrf('pages/agreements/view.php?id=' . $agreement['id']);

try {
    db_transaction(function () use ($agreement) {
        $status = db_value('SELECT status FROM agreements WHERE id = :id FOR UPDATE', [':id' => $agreement['id']]);
        if ($status !== 'draft') {
            throw new DomainException('Only draft agreements can be deleted. Cancel this agreement instead.');
        }
        if ((int) db_value('SELECT COUNT(*) FROM demand_letters WHERE agreement_id = :id', [':id' => $agreement['id']]) > 0) {
            throw new DomainException('This agreement is referenced by demand letters and must be kept.');
        }
        db_exec('DELETE FROM agreement_signatories WHERE agreement_id = :id', [':id' => $agreement['id']]);
        db_exec('DELETE FROM agreement_documents WHERE agreement_id = :id', [':id' => $agreement['id']]);
        db_exec('DELETE FROM agreements WHERE id = :id', [':id' => $agreement['id']]);
    });

    log_activity('delete', 'agreement', $agreement['id'], 'Deleted draft agreement ' . $agreement['agreement_number']);
    flash('success', 'Draft agreement ' . $agreement['agreement_number'] . ' was deleted.');
    redirect('pages/agreements/index.php');
} catch (DomainException $e) {
    flash('error', $e->getMessage());
} catch (PDOException $e) {
    flash('error', db_error_message($e));
}

redirect('pages/agreements/view.php', ['id' => $agreement['id']]);
