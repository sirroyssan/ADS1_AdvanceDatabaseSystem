<?php
/**
 * Demand Letters - delete a draft letter.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/letter_functions.php';

$currentUser = require_admin();

if (!is_post()) {
    redirect('pages/demand_letters/index.php');
}

$letter = find_or_redirect(letter_find(request_id()), 'pages/demand_letters/index.php', 'Demand letter');
require_csrf('pages/demand_letters/view.php?id=' . $letter['id']);

$count = db_exec("DELETE FROM demand_letters WHERE id = :id AND status = 'draft'", [':id' => $letter['id']]);

if ($count) {
    log_activity('delete', 'demand_letter', $letter['id'], 'Deleted draft demand letter ' . $letter['letter_number']);
    flash('success', 'Draft letter ' . $letter['letter_number'] . ' was deleted.');
    redirect('pages/demand_letters/index.php');
}

flash('error', 'Only draft letters can be deleted.');
redirect('pages/demand_letters/view.php', ['id' => $letter['id']]);
