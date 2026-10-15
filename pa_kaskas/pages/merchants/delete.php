<?php
/**
 * Merchants - delete (POST only). Blocked while transactions use the merchant.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/merchant_functions.php';

$currentUser = require_admin();

if (!is_post()) {
    redirect('pages/merchants/index.php');
}

$merchant = find_or_redirect(merchant_find(request_id()), 'pages/merchants/index.php', 'Merchant');
require_csrf('pages/merchants/view.php?id=' . $merchant['id']);

try {
    db_transaction(function (PDO $pdo) use ($merchant) {
        db_value('SELECT id FROM merchants WHERE id = :id FOR UPDATE', [':id' => $merchant['id']]);
        $usage = merchant_usage($merchant['id']);
        if ($usage['total'] > 0) {
            throw new DomainException('This merchant cannot be deleted because it is used by ' . merchant_usage_text($usage) . '.');
        }
        db_exec('DELETE FROM merchants WHERE id = :id', [':id' => $merchant['id']]);
    });

    log_activity('delete', 'merchant', $merchant['id'], 'Deleted merchant "' . $merchant['merchant_name'] . '"', $merchant);
    flash('success', 'Merchant "' . $merchant['merchant_name'] . '" was deleted.');
    redirect('pages/merchants/index.php');
} catch (DomainException $e) {
    flash('error', $e->getMessage());
} catch (PDOException $e) {
    flash('error', db_error_message($e));
}

redirect('pages/merchants/view.php', ['id' => $merchant['id']]);
