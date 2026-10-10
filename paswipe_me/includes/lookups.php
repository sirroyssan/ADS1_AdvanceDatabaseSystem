<?php
/**
 * Dropdown option lists for foreign keys, shared by all modules, so an
 * administrator always picks a related record by name instead of typing ids.
 *
 * $includeId keeps the record currently saved on an edit form in the list
 * even if it is inactive/closed now.
 */

defined('APP_ROOT') or exit('No direct access.');

// ENUM values copied from the schema (source of truth).
const ENUM_USER_STATUS        = ['pending', 'active', 'suspended', 'locked', 'inactive'];
const ENUM_USER_ROLE          = ['owner', 'admin', 'borrower'];
const ENUM_BORROWER_STATUS    = ['good_standing', 'payment_due', 'late', 'delinquent', 'restricted', 'inactive'];
const ENUM_CARD_TYPE          = ['credit', 'charge', 'other'];
const ENUM_CARD_NETWORK       = ['visa', 'mastercard', 'amex', 'jcb', 'unionpay', 'other'];
const ENUM_CARD_STATUS        = ['active', 'blocked', 'expired', 'closed', 'inactive'];
const ENUM_DOCUMENT_TYPE      = ['receipt', 'official_receipt', 'sales_invoice', 'delivery_receipt', 'product_photo', 'warranty', 'serial_photo', 'payment_proof', 'bank_statement', 'credit_card_statement', 'cash_loan_offer', 'cash_loan_approval', 'bank_correspondence', 'agreement', 'signed_agreement', 'demand_letter', 'other'];
const ENUM_TXN_TYPE           = ['personal', 'card_user', 'family', 'friend', 'other'];
const ENUM_TXN_PAYMENT_TYPE   = ['straight', 'installment', 'cash_loan', 'cash_advance', 'other'];
const ENUM_TXN_STATUS         = ['draft', 'posted', 'active', 'completed', 'cancelled', 'refunded', 'disputed'];
const ENUM_FREQUENCY          = ['weekly', 'biweekly', 'monthly', 'quarterly', 'other'];
const ENUM_SCHEDULE_STATUS    = ['upcoming', 'due', 'partially_paid', 'paid', 'overdue', 'waived', 'cancelled'];
const ENUM_CASH_LOAN_STATUS   = ['offered', 'accepted', 'disbursed', 'active', 'completed', 'cancelled', 'rejected'];
const ENUM_CASH_ADVANCE_STATUS = ['active', 'completed', 'cancelled'];
const ENUM_OBLIGATION_STATUS  = ['pending', 'active', 'partially_paid', 'paid', 'overdue', 'delinquent', 'cancelled', 'written_off'];
const ENUM_PAYMENT_METHOD     = ['gcash', 'maya', 'maribank', 'bdo', 'bpi', 'metrobank', 'bank_transfer', 'cash', 'other'];
const ENUM_PAYMENT_STATUS     = ['submitted', 'under_review', 'approved', 'rejected', 'correction_required', 'cancelled'];
const ENUM_ALLOCATION_STATUS  = ['unallocated', 'partially_allocated', 'fully_allocated', 'overallocated'];
const ENUM_AGREEMENT_STATUS   = ['draft', 'generated', 'active', 'signed', 'completed', 'cancelled'];
const ENUM_SIGNATORY_STATUS   = ['pending', 'signed', 'declined'];
const ENUM_LETTER_STATUS      = ['draft', 'approved', 'sent', 'acknowledged', 'resolved', 'cancelled'];
const ENUM_NOTIFY_CHANNEL     = ['in_app', 'email', 'sms', 'push'];
const ENUM_NOTIFY_STATUS      = ['pending', 'sent', 'delivered', 'read', 'failed', 'cancelled'];

// Colour tones for status badges (text is always shown too).
const TONES = [
    'active' => 'success', 'good_standing' => 'success', 'paid' => 'success', 'approved' => 'success',
    'completed' => 'success', 'signed' => 'success', 'delivered' => 'success', 'read' => 'success',
    'resolved' => 'success', 'fully_allocated' => 'success', 'disbursed' => 'success', 'posted' => 'info',
    'pending' => 'warning', 'submitted' => 'warning', 'under_review' => 'warning', 'payment_due' => 'warning',
    'partially_paid' => 'warning', 'due' => 'warning', 'correction_required' => 'warning', 'draft' => 'muted',
    'offered' => 'info', 'accepted' => 'info', 'generated' => 'info', 'sent' => 'info', 'upcoming' => 'info',
    'partially_allocated' => 'warning', 'acknowledged' => 'info', 'disputed' => 'warning',
    'late' => 'danger', 'delinquent' => 'danger', 'overdue' => 'danger', 'rejected' => 'danger',
    'suspended' => 'danger', 'locked' => 'danger', 'blocked' => 'danger', 'restricted' => 'danger',
    'failed' => 'danger', 'declined' => 'danger', 'overallocated' => 'danger', 'written_off' => 'danger',
    'cancelled' => 'muted', 'inactive' => 'muted', 'closed' => 'muted', 'expired' => 'muted',
    'refunded' => 'muted', 'waived' => 'muted', 'unallocated' => 'muted',
];

function badge(?string $value): string
{
    return status_badge($value, TONES);
}

/**
 * Add the saved record to an option list if a filter removed it.
 */
function keep_option(array $options, ?string $includeId, string $sql): array
{
    if ($includeId !== null && $includeId !== '' && !isset($options[$includeId])) {
        $label = db_value($sql, [':id' => $includeId]);
        if ($label !== false) {
            $options[$includeId] = $label . ' (inactive)';
        }
    }

    return $options;
}

function category_options(?string $includeId = null): array
{
    $options = db_options(
        "SELECT c.id, IF(p.id IS NULL, c.name, CONCAT(p.name, ' › ', c.name)) AS label
           FROM categories c LEFT JOIN categories p ON p.id = c.parent_id
          WHERE c.is_active = 1 ORDER BY label"
    );

    return keep_option($options, $includeId, 'SELECT name FROM categories WHERE id = :id');
}

function merchant_options(?string $includeId = null): array
{
    return db_options(
        "SELECT id, IF(branch IS NULL OR branch = '', merchant_name, CONCAT(merchant_name, ' — ', branch)) AS label
           FROM merchants ORDER BY label"
    );
}

function borrower_options(?string $includeId = null, bool $activeOnly = true): array
{
    $options = db_options(
        "SELECT id, CONCAT(last_name, ', ', first_name, ' (', borrower_number, ')') AS label
           FROM borrowers " . ($activeOnly ? "WHERE status <> 'inactive' " : '') . 'ORDER BY last_name, first_name'
    );

    return keep_option($options, $includeId, "SELECT CONCAT(last_name, ', ', first_name, ' (', borrower_number, ')') FROM borrowers WHERE id = :id");
}

function credit_card_options(?string $includeId = null, bool $activeOnly = true): array
{
    $options = db_options(
        "SELECT id, CONCAT(card_nickname, ' — ', issuer, ' •••• ', last_four) AS label
           FROM credit_cards " . ($activeOnly ? "WHERE status = 'active' " : '') . 'ORDER BY card_nickname'
    );

    return keep_option($options, $includeId, "SELECT CONCAT(card_nickname, ' — ', issuer, ' •••• ', last_four) FROM credit_cards WHERE id = :id");
}

/**
 * Users who hold the owner or admin role (card owners, reviewers).
 */
function admin_user_options(?string $includeId = null): array
{
    $options = db_options(
        "SELECT DISTINCT u.id,
                COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), ''), u.username, u.email) AS label
           FROM users u JOIN user_roles r ON r.user_id = u.id
          WHERE r.role IN ('owner', 'admin') AND u.status = 'active'
          ORDER BY label"
    );

    return keep_option($options, $includeId, "SELECT COALESCE(NULLIF(TRIM(CONCAT_WS(' ', first_name, last_name)), ''), username, email) FROM users WHERE id = :id");
}

function all_user_options(): array
{
    return db_options(
        "SELECT id, CONCAT(COALESCE(NULLIF(TRIM(CONCAT_WS(' ', first_name, last_name)), ''), username, email), ' <', email, '>') AS label
           FROM users ORDER BY label"
    );
}

/**
 * Statements with the card id as data attribute (for card -> statement filtering).
 *
 * @return array{0: array, 1: array} [options, option_data]
 */
function statement_options(): array
{
    $rows = db_all(
        "SELECT s.id, s.credit_card_id,
                CONCAT(c.card_nickname, ' •••• ', c.last_four, ' — ', DATE_FORMAT(s.statement_date, '%b %e, %Y'),
                       IFNULL(CONCAT(' (#', s.statement_number, ')'), '')) AS label
           FROM credit_card_statements s JOIN credit_cards c ON c.id = s.credit_card_id
          WHERE s.soa_recorded_at IS NOT NULL
          ORDER BY s.statement_date DESC"
    );

    $options = [];
    $data = [];
    foreach ($rows as $row) {
        $options[$row['id']] = $row['label'];
        $data[$row['id']] = ['parent' => $row['credit_card_id']];
    }

    return [$options, $data];
}

function document_options(?string $includeId = null): array
{
    $options = db_options(
        "SELECT id, CONCAT(original_filename, ' (', REPLACE(document_type, '_', ' '), ')') AS label
           FROM documents WHERE is_deleted = 0 ORDER BY uploaded_at DESC"
    );

    return keep_option($options, $includeId, 'SELECT original_filename FROM documents WHERE id = :id');
}

/**
 * Payment methods. $ownerOnly limits to the owner's own accounts (card payments).
 *
 * @return array{0: array, 1: array} [options, option_data with borrower id]
 */
function payment_method_options(bool $ownerOnly = false, ?string $includeId = null): array
{
    $rows = db_all(
        "SELECT pm.id, pm.borrower_id,
                CONCAT(pm.display_name, ' — ', UPPER(REPLACE(pm.method_type, '_', ' ')),
                       IFNULL(CONCAT(' •••• ', pm.last_four), ''),
                       IF(pm.borrower_id IS NULL, ' (owner)', CONCAT(' (', b.first_name, ' ', b.last_name, ')'))) AS label
           FROM payment_methods pm LEFT JOIN borrowers b ON b.id = pm.borrower_id
          WHERE (pm.is_active = 1 OR pm.id = :keep)" . ($ownerOnly ? ' AND pm.borrower_id IS NULL' : '') . '
          ORDER BY pm.display_name',
        [':keep' => $includeId ?? '']
    );

    $options = [];
    $data = [];
    foreach ($rows as $row) {
        $options[$row['id']] = $row['label'];
        $data[$row['id']] = ['parent' => $row['borrower_id'] ?? 'owner'];
    }

    return [$options, $data];
}

/**
 * Transactions with their borrower as data attribute.
 *
 * @return array{0: array, 1: array}
 */
function transaction_options(?string $includeId = null): array
{
    $rows = db_all(
        "SELECT t.id, t.borrower_id,
                CONCAT(t.transaction_number, ' — ', DATE_FORMAT(t.transaction_date, '%b %e, %Y'), ' — ₱', FORMAT(t.amount, 2)) AS label
           FROM transactions t
          WHERE t.status <> 'cancelled' OR t.id = :keep
          ORDER BY t.transaction_date DESC",
        [':keep' => $includeId ?? '']
    );

    $options = [];
    $data = [];
    foreach ($rows as $row) {
        $options[$row['id']] = $row['label'];
        $data[$row['id']] = ['parent' => $row['borrower_id'] ?? ''];
    }

    return [$options, $data];
}

/**
 * Obligations with their borrower as data attribute.
 *
 * @return array{0: array, 1: array}
 */
function obligation_options(?string $includeId = null, bool $openOnly = false): array
{
    $rows = db_all(
        "SELECT o.id, o.borrower_id,
                CONCAT(o.obligation_number, ' — outstanding ₱', FORMAT(o.outstanding_amount, 2)) AS label
           FROM borrower_obligations o
          WHERE " . ($openOnly
            ? "(o.status NOT IN ('cancelled', 'paid', 'written_off') AND o.outstanding_amount > 0)"
            : "o.status <> 'cancelled'") . ' OR o.id = :keep
          ORDER BY o.obligation_date DESC',
        [':keep' => $includeId ?? '']
    );

    $options = [];
    $data = [];
    foreach ($rows as $row) {
        $options[$row['id']] = $row['label'];
        $data[$row['id']] = ['parent' => $row['borrower_id']];
    }

    return [$options, $data];
}

function agreement_template_options(?string $includeId = null): array
{
    $options = db_options("SELECT id, CONCAT(name, ' (v', version, ')') FROM agreement_templates WHERE is_active = 1 ORDER BY name");

    return keep_option($options, $includeId, 'SELECT name FROM agreement_templates WHERE id = :id');
}

function letter_template_options(?string $includeId = null): array
{
    $options = db_options("SELECT id, CONCAT(name, ' (v', version, ')') FROM demand_letter_templates WHERE is_active = 1 ORDER BY name");

    return keep_option($options, $includeId, 'SELECT name FROM demand_letter_templates WHERE id = :id');
}

function penalty_rule_options(?string $includeId = null): array
{
    $options = db_options('SELECT id, name FROM penalty_rules WHERE is_active = 1 ORDER BY name');

    return keep_option($options, $includeId, 'SELECT name FROM penalty_rules WHERE id = :id');
}

/**
 * Check that a selected child record (statement, transaction, payment
 * method...) belongs to the selected parent (card, borrower...).
 */
function belongs_to(string $table, string $id, string $column, ?string $parentId): bool
{
    return (int) db_value(
        'SELECT COUNT(*) FROM ' . db_identifier($table) . ' WHERE id = :id AND ' . db_identifier($column) . ' <=> :parent',
        [':id' => $id, ':parent' => $parentId]
    ) === 1;
}

/* ------------------------------------------------------------------
 * Borrower relationship (borrowers.relationship is VARCHAR(100)).
 * Family members may submit payments without proof; everyone else must
 * attach proof of payment.
 * ------------------------------------------------------------------ */

const RELATIONSHIP_FAMILY = [
    'sister'   => 'Sister',
    'brother'  => 'Brother',
    'parent'   => 'Parent',
    'child'    => 'Son / daughter',
    'spouse'   => 'Spouse / partner',
    'cousin'   => 'Cousin',
    'relative' => 'Other relative',
];
const RELATIONSHIP_OTHERS = [
    'friend'    => 'Friend',
    'colleague' => 'Colleague / co-worker',
    'neighbor'  => 'Neighbor',
    'other'     => 'Other (not related)',
];

/**
 * Options for the relationship select, keeping an older typed-in value.
 */
function relationship_options(?string $current = null): array
{
    $options = [];
    foreach (RELATIONSHIP_FAMILY as $value => $label) {
        $options[$value] = 'Family — ' . $label;
    }
    foreach (RELATIONSHIP_OTHERS as $value => $label) {
        $options[$value] = 'Not related — ' . $label;
    }
    if ($current !== null && $current !== '' && !isset($options[$current])) {
        $options[$current] = $current . ' (' . (relationship_is_family($current) ? 'family' : 'not related') . ')';
    }

    return $options;
}

/**
 * Is this relationship immediate family / a relative? Also understands
 * older free-text values such as "sibling", "tita", "mother-in-law".
 */
function relationship_is_family(?string $relationship): bool
{
    $value = strtolower(trim((string) $relationship));
    if ($value === '') {
        return false;
    }
    if (isset(RELATIONSHIP_FAMILY[$value])) {
        return true;
    }

    return (bool) preg_match(
        '/\b(sisters?|brothers?|siblings?|relatives?|family|mother|father|mom|mum|dad|parents?|sons?|daughters?|child(ren)?|spouse|wife|husband|partner|'
        . 'cousins?|aunt|auntie|uncle|tita|tito|niece|nephew|grand\w*|lola|lolo|ate|kuya|in-law|inlaw|step\w*)\b/u',
        $value
    );
}

/* ------------------------------------------------------------------
 * Banks (card issuers). credit_cards.issuer and penalty_rules.applies_to_issuer
 * are chosen from this one list, so a rule's bank always matches its cards.
 * Aliases let older typed-in values match, e.g. "Bank of the Philippine
 * Islands" = "BPI".
 * ------------------------------------------------------------------ */

const BANKS = [
    'AUB'                => ['asia united bank'],
    'BDO'                => ['bdo unibank', 'banco de oro'],
    'BPI'                => ['bank of the philippine islands'],
    'Chinabank'          => ['china bank', 'china banking corporation'],
    'CIMB'               => ['cimb bank'],
    'Citibank'           => ['citi'],
    'EastWest'           => ['eastwest bank', 'east west bank', 'eastwest banking corporation'],
    'GoTyme'             => ['gotyme bank'],
    'HSBC'               => ['hongkong and shanghai banking corporation'],
    'Landbank'           => ['land bank', 'land bank of the philippines'],
    'Maybank'            => ['maybank philippines'],
    'Maya'               => ['maya bank', 'paymaya'],
    'Metrobank'          => ['metropolitan bank', 'metropolitan bank and trust company'],
    'PNB'                => ['philippine national bank'],
    'PSBank'             => ['philippine savings bank'],
    'RCBC'               => ['rizal commercial banking corporation'],
    'Robinsons Bank'     => ['robinsons'],
    'Security Bank'      => ['securitybank', 'security bank corporation'],
    'Sterling Bank'      => ['sterling bank of asia'],
    'UnionBank'          => ['union bank', 'union bank of the philippines', 'ubp'],
];

/**
 * Canonical bank name for a typed or stored value ("unionbank" -> "UnionBank").
 * Unknown names are returned trimmed, so they still match themselves.
 */
function bank_canonical(?string $name): ?string
{
    $key = mb_strtolower(trim(preg_replace('/\s+/', ' ', (string) $name)));
    if ($key === '') {
        return null;
    }
    foreach (BANKS as $bank => $aliases) {
        if ($key === mb_strtolower($bank) || in_array($key, $aliases, true) || str_replace(' ', '', $key) === str_replace(' ', '', mb_strtolower($bank))) {
            return $bank;
        }
    }

    return trim((string) $name);
}

function same_bank(?string $a, ?string $b): bool
{
    $a = bank_canonical($a);

    return $a !== null && mb_strtolower($a) === mb_strtolower((string) bank_canonical($b));
}

/**
 * Bank dropdown: every listed bank, plus any other bank already used on a
 * card, plus the current value. Banks you have cards with are marked.
 */
function bank_options(?string $current = null): array
{
    $mine = [];
    foreach (db_all("SELECT DISTINCT issuer FROM credit_cards WHERE TRIM(issuer) <> ''") as $row) {
        $mine[(string) bank_canonical($row['issuer'])] = true;
    }

    $names = array_keys(BANKS);
    foreach (array_keys($mine) as $bank) {
        $names[] = $bank;
    }
    if ($current !== null && trim($current) !== '') {
        $names[] = trim($current);
    }
    $names = array_unique($names);
    natcasesort($names);

    $options = [];
    foreach ($names as $name) {
        $options[$name] = $name . (isset($mine[$name]) ? ' — you have a card' : '');
    }

    return $options;
}
