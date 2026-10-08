<?php
/**
 * Payment Methods - delete (unused only) or deactivate (soft delete via is_active).
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/payment_method_functions.php';

$currentUser = require_admin();

if (!is_post()) {
    redirect('pages/payment_methods/index.php');
}

$method = find_or_redirect(payment_method_find(request_id()), 'pages/payment_methods/index.php', 'Payment method');
require_csrf('pages/payment_methods/view.php?id=' . $method['id']);

$action = input_string($_POST, 'action');

if ($action === 'deactivate') {
    db_update('payment_methods', ['is_active' => 0], $method['id']);
    log_activity('deactivate', 'payment_method', $method['id'], 'Deactivated payment method "' . $method['display_name'] . '"', ['is_active' => 1], ['is_active' => 0]);
    flash('success', 'Payment method "' . $method['display_name'] . '" was deactivated.');
    redirect('pages/payment_methods/view.php', ['id' => $method['id']]);
}

try {
    db_transaction(function () use ($method) {
        db_value('SELECT id FROM payment_methods WHERE id = :id FOR UPDATE', [':id' => $method['id']]);
        $usage = payment_method_usage($method['id']);
        if ($usage['total'] > 0) {
            throw new DomainException('This payment method cannot be deleted because it is used by ' . usage_text($usage) . '. Deactivate it instead.');
        }
        db_exec('DELETE FROM payment_methods WHERE id = :id', [':id' => $method['id']]);
    });

    log_activity('delete', 'payment_method', $method['id'], 'Deleted payment method "' . $method['display_name'] . '"', $method);
    flash('success', 'Payment method "' . $method['display_name'] . '" was deleted.');
    redirect('pages/payment_methods/index.php');
} catch (DomainException $e) {
    flash('error', $e->getMessage());
} catch (PDOException $e) {
    flash('error', db_error_message($e));
}

redirect('pages/payment_methods/view.php', ['id' => $method['id']]);
