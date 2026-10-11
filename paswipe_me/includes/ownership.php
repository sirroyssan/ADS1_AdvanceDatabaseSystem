<?php
/**
 * Multi-user ownership: every financial record belongs to exactly one user
 * (its owner) and is visible and changeable only by that user.
 *
 *   credit_cards.owner_user_id                     the card's owner
 *   transactions, bank_charges, cash_advances,     through their card
 *   cash_loans, credit_card_payments,
 *   credit_card_statements, card_billing_cycles
 *   installment_plans / schedules, cash loan       through their transaction / loan
 *   schedules and allocations, statement items
 *   borrowers.owner_user_id                        the lender who records them
 *   borrower_obligations, borrower_payments,       through their borrower
 *   agreements, demand_letters
 *   penalty_rules, agreement_templates,            their own owner_user_id
 *   demand_letter_templates, documents
 *   payment_methods                                owner_user_id, or a borrower of the owner
 *
 * Merchants and categories are shared reference lists (names only, no amounts);
 * whatever they show about transactions is scoped like everything else.
 *
 * Administrators manage accounts; they do NOT see other users' financial
 * records - every query below is scoped to the signed-in user, admin or not.
 *
 * A future "shared card" feature would only need to widen owned_cards_sql()
 * (e.g. OR id IN (SELECT credit_card_id FROM credit_card_shares WHERE user_id = me
 * AND permission …)); every module reads card ownership through it.
 */

defined('APP_ROOT') or exit('No direct access.');

/** Matches no record: a borrower account that is not linked to a borrower yet. */
const OWNER_NONE = '00000000-0000-0000-0000-000000000000';

/**
 * The owner whose records the signed-in user works with: their own id; for a
 * borrower portal account, the lender who records that borrower (the portal
 * pages then narrow everything down to the borrower's own records, see
 * includes/portal.php). A borrower can never reach another lender's records.
 */
function owner_id(): string
{
    static $lender = [];
    $id = (string) ($_SESSION['user_id'] ?? '');
    if (!is_valid_uuid($id)) {
        // Background jobs and CLI tools never reach scoped queries; a page without a user is a bug.
        throw new RuntimeException('No signed-in user for an owner-scoped query.');
    }
    if (array_intersect(ADMIN_ROLES, (array) ($_SESSION['user_roles'] ?? []))) {
        return $id;
    }

    return $lender[$id] ??= (string) (db_value(
        'SELECT b.owner_user_id FROM borrower_user_accounts a JOIN borrowers b ON b.id = a.borrower_id
          WHERE a.user_id = :u AND b.owner_user_id IS NOT NULL ORDER BY a.activated_at LIMIT 1',
        [':u' => $id]
    ) ?: OWNER_NONE);
}

/** The owner id quoted for SQL (a UUID from the session, never from the request). */
function owner_sql(): string
{
    return db()->quote(owner_id());
}

function owned_cards_sql(): string
{
    return '(SELECT oc.id FROM credit_cards oc WHERE oc.owner_user_id = ' . owner_sql() . ')';
}

function owned_borrowers_sql(): string
{
    return '(SELECT ob.id FROM borrowers ob WHERE ob.owner_user_id = ' . owner_sql() . ')';
}

/** Tables and how they reach their owner. */
const OWNERSHIP = [
    'credit_cards'            => 'owner',
    'borrowers'               => 'owner',
    'penalty_rules'           => 'owner',
    'agreement_templates'     => 'owner',
    'demand_letter_templates' => 'owner',
    'documents'               => 'owner',
    'transactions'            => 'card',
    'bank_charges'            => 'card',
    'cash_advances'           => 'card',
    'cash_loans'              => 'card',
    'credit_card_payments'    => 'card',
    'credit_card_statements'  => 'card',
    'card_billing_cycles'     => 'card',
    'statement_items'         => 'card',
    'installment_plans'       => 'transaction',
    'borrower_obligations'    => 'borrower',
    'borrower_payments'       => 'borrower',
    'agreements'              => 'borrower',
    'demand_letters'          => 'borrower',
    'payment_methods'         => 'payment_method',
];

/**
 * WHERE condition: row alias $alias of $table belongs to the signed-in user.
 */
function owner_scope(string $table, string $alias): string
{
    $a = preg_replace('/[^a-z0-9_]/i', '', $alias);

    return match (OWNERSHIP[$table] ?? null) {
        'owner'          => "$a.owner_user_id = " . owner_sql(),
        'card'           => "$a.credit_card_id IN " . owned_cards_sql(),
        'transaction'    => "$a.transaction_id IN (SELECT ot.id FROM transactions ot WHERE ot.credit_card_id IN " . owned_cards_sql() . ')',
        'borrower'       => "$a.borrower_id IN " . owned_borrowers_sql(),
        'payment_method' => "($a.owner_user_id = " . owner_sql() . " OR $a.borrower_id IN " . owned_borrowers_sql() . ')',
        default          => throw new LogicException('No ownership rule for ' . $table),
    };
}

/** Does record $id of $table belong to the signed-in user? */
function owns(string $table, ?string $id): bool
{
    if (!is_valid_uuid((string) $id)) {
        return false;
    }

    return (int) db_value('SELECT COUNT(*) FROM ' . db_identifier($table) . ' r WHERE r.id = :id AND ' . owner_scope($table, 'r'), [':id' => $id]) === 1;
}

/** Stop the request when a record does not belong to the user (treated as "not found"). */
function require_owned(string $table, ?string $id, string $back, string $noun = 'Record'): void
{
    if (!owns($table, $id)) {
        log_activity('access_denied', $table, is_valid_uuid((string) $id) ? $id : null, 'Not the owner: ' . ($_SERVER['REQUEST_URI'] ?? ''));
        flash('error', $noun . ' not found.');
        redirect($back);
    }
}

/** Sign-in accounts linked to the user's own borrowers. */
function owned_borrower_users_sql(): string
{
    return '(SELECT bua.user_id FROM borrower_user_accounts bua WHERE bua.borrower_id IN ' . owned_borrowers_sql() . ')';
}

/**
 * Activity log entries a user may read: their own, those of their borrowers'
 * portal accounts, and - for administrators - account and security events
 * (sign-ins, accounts, system jobs), which carry no financial details.
 */
function activity_scope(string $alias): string
{
    $a = preg_replace('/[^a-z0-9_]/i', '', $alias);
    $sql = "($a.actor_user_id = " . owner_sql() . " OR $a.actor_user_id IN " . owned_borrower_users_sql();
    if (in_array('admin', (array) ($_SESSION['user_roles'] ?? []), true)) {
        $sql .= " OR $a.entity_type IN ('user', 'page', 'system')";
    }

    return $sql . ')';
}
