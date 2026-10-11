<?php
/**
 * Borrowers - actions on child records (POST):
 * add/delete address, make address primary, add/delete contact,
 * link/unlink sign-in account (borrower_user_accounts).
 */

require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/borrower_functions.php';

$currentUser = require_admin();

if (!is_post()) {
    redirect('pages/borrowers/index.php');
}

$borrower = find_or_redirect(borrower_find(request_id()), 'pages/borrowers/index.php', 'Borrower');
$back = 'pages/borrowers/view.php?id=' . $borrower['id'];
require_csrf($back);

$action = input_string($_POST, 'action');
$itemId = input_string($_POST, 'item_id');

try {
    switch ($action) {
        case 'add_address':
            [$data, $errors] = validate_fields(address_fields(), $_POST);
            if ($errors) {
                flash('error', 'Address not saved: ' . reset($errors));
                break;
            }
            db_transaction(function () use ($borrower, $data) {
                if ($data['is_primary'] === 1) {
                    db_exec('UPDATE borrower_addresses SET is_primary = 0 WHERE borrower_id = :b', [':b' => $borrower['id']]);
                }
                db_insert('borrower_addresses', ['id' => new_uuid(), 'borrower_id' => $borrower['id']] + $data);
            });
            log_activity('add_address', 'borrower', $borrower['id'], 'Added address', null, $data);
            flash('success', 'Address was added.');
            break;

        case 'primary_address':
            if (!belongs_to('borrower_addresses', $itemId, 'borrower_id', $borrower['id'])) {
                flash('error', 'Address not found.');
                break;
            }
            db_transaction(function () use ($borrower, $itemId) {
                db_exec('UPDATE borrower_addresses SET is_primary = (id = :a) WHERE borrower_id = :b', [':a' => $itemId, ':b' => $borrower['id']]);
            });
            flash('success', 'Primary address updated.');
            break;

        case 'delete_address':
            $count = db_exec('DELETE FROM borrower_addresses WHERE id = :a AND borrower_id = :b', [':a' => $itemId, ':b' => $borrower['id']]);
            if ($count) {
                log_activity('delete_address', 'borrower', $borrower['id'], 'Deleted address');
            }
            flash($count ? 'success' : 'error', $count ? 'Address was deleted.' : 'Address not found.');
            break;

        case 'add_contact':
            [$data, $errors] = validate_fields(contact_fields(), $_POST);
            if ($errors) {
                flash('error', 'Contact not saved: ' . reset($errors));
                break;
            }
            db_insert('borrower_contacts', ['id' => new_uuid(), 'borrower_id' => $borrower['id']] + $data);
            log_activity('add_contact', 'borrower', $borrower['id'], 'Added contact ' . ($data['contact_name'] ?? $data['contact_type']), null, $data);
            flash('success', 'Contact was added.');
            break;

        case 'delete_contact':
            $count = db_exec('DELETE FROM borrower_contacts WHERE id = :c AND borrower_id = :b', [':c' => $itemId, ':b' => $borrower['id']]);
            if ($count) {
                log_activity('delete_contact', 'borrower', $borrower['id'], 'Deleted contact');
            }
            flash($count ? 'success' : 'error', $count ? 'Contact was deleted.' : 'Contact not found.');
            break;

        case 'link_account':
        case 'create_account':
            if (db_value('SELECT COUNT(*) FROM borrower_user_accounts WHERE borrower_id = :b', [':b' => $borrower['id']]) > 0) {
                flash('error', 'This borrower already has a linked account.');
                break;
            }
            $email = mb_strtolower(input_string($_POST, 'email'));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 255) {
                flash('error', 'Enter a valid email address.');
                break;
            }
            if ($action === 'link_account') {
                $userId = linkable_borrower_user($email);
                if ($userId === null) {
                    flash('error', 'No borrower sign-in account with that email is waiting to be linked. Create a new one instead.');
                    break;
                }
            } else {
                $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
                $problems = password_policy_errors($password, is_string($_POST['password_confirm'] ?? null) ? $_POST['password_confirm'] : '');
                if ($problems) {
                    flash('error', 'Account not created: ' . reset($problems));
                    break;
                }
                if (db_value('SELECT COUNT(*) FROM users WHERE LOWER(email) = :e', [':e' => $email]) > 0) {
                    flash('error', 'An account with this email already exists. Use "Link existing account" if it is this borrower\'s.');
                    break;
                }
                $userId = new_uuid();
            }
            db_transaction(function () use ($action, $userId, $email, $borrower) {
                if ($action === 'create_account') {
                    db_insert('users', [
                        'id' => $userId, 'email' => $email, 'first_name' => $borrower['first_name'], 'last_name' => $borrower['last_name'],
                        'status' => 'active', 'password_hash' => password_hash((string) $_POST['password'], PASSWORD_DEFAULT),
                    ]);
                    db_insert('user_roles', ['id' => new_uuid(), 'user_id' => $userId, 'role' => 'borrower']);
                    log_activity('create', 'user', $userId, 'Created borrower sign-in account ' . $email, null, ['email' => $email, 'roles' => ['borrower']]);
                }
                db_insert('borrower_user_accounts', [
                    'id' => new_uuid(), 'user_id' => $userId, 'borrower_id' => $borrower['id'], 'activated_at' => date('Y-m-d H:i:s'),
                ]);
            });
            log_activity('link_account', 'borrower', $borrower['id'], 'Linked sign-in account ' . $email, null, ['user_id' => $userId]);
            flash('success', $action === 'create_account' ? 'Sign-in account created. The borrower can now sign in with ' . $email . '.' : 'Sign-in account was linked.');
            break;

        case 'unlink_account':
            $count = db_exec('DELETE FROM borrower_user_accounts WHERE id = :a AND borrower_id = :b', [':a' => $itemId, ':b' => $borrower['id']]);
            if ($count) {
                log_activity('unlink_account', 'borrower', $borrower['id'], 'Unlinked sign-in account');
            }
            flash($count ? 'success' : 'error', $count ? 'Sign-in account was unlinked.' : 'Link not found.');
            break;

        default:
            flash('error', 'Unknown action.');
    }
} catch (PDOException $e) {
    flash('error', db_error_message($e));
}

redirect($back);
