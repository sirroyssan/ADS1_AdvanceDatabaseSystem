<?php
/**
 * Credit Cards - delete (no history only) or mark inactive.
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/credit_card_functions.php';

$currentUser = require_admin();

if (!is_post()) {
    redirect('pages/credit_cards/index.php');
}

$card = find_or_redirect(credit_card_find(request_id()), 'pages/credit_cards/index.php', 'Credit card');
require_csrf('pages/credit_cards/view.php?id=' . $card['id']);

if (input_string($_POST, 'action') === 'deactivate') {
    db_update('credit_cards', ['status' => 'inactive'], $card['id']);
    log_activity('deactivate', 'credit_card', $card['id'], 'Marked card ' . credit_card_label($card) . ' inactive', ['status' => $card['status']], ['status' => 'inactive']);
    flash('success', 'Card "' . $card['card_nickname'] . '" was marked as inactive.');
    redirect('pages/credit_cards/view.php', ['id' => $card['id']]);
}

try {
    db_transaction(function () use ($card) {
        db_value('SELECT id FROM credit_cards WHERE id = :id FOR UPDATE', [':id' => $card['id']]);
        $usage = credit_card_usage($card['id']);
        if ($usage['total'] > 0) {
            throw new DomainException('This card cannot be deleted because it has ' . usage_text($usage) . '. Mark it inactive instead.');
        }
        db_exec('DELETE FROM card_billing_cycles WHERE credit_card_id = :id', [':id' => $card['id']]);
        db_exec('DELETE FROM credit_cards WHERE id = :id', [':id' => $card['id']]);
    });

    log_activity('delete', 'credit_card', $card['id'], 'Deleted card ' . credit_card_label($card), $card);
    flash('success', 'Card "' . $card['card_nickname'] . '" was deleted.');
    redirect('pages/credit_cards/index.php');
} catch (DomainException $e) {
    flash('error', $e->getMessage());
} catch (PDOException $e) {
    flash('error', db_error_message($e));
}

redirect('pages/credit_cards/view.php', ['id' => $card['id']]);
