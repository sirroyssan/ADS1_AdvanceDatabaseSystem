<?php
/**
 * Multi-user upgrade (db/upgrade_multiuser.sql): backup, owner columns and
 * the owner of every existing record.
 *
 * How existing records get their owner (never guessed):
 *   - cards already have one (credit_cards.owner_user_id);
 *   - a borrower belongs to the owner of the cards their records are on
 *     (their transactions, obligations, cash loan allocations);
 *   - a penalty rule / template belongs to the owner of the borrowers it was
 *     used for; a document to the owner of the record it is attached to;
 *   - nothing to follow: the owner or administrator who created the record
 *     (borrowers.created_by, documents.uploaded_by); else, when every card in
 *     the database has the same owner (the single-owner system this upgrade
 *     starts from), that owner;
 *   - otherwise (or when the links point to two owners) the record is
 *     flagged: owner_user_id stays NULL, nobody sees it, and an administrator
 *     confirms its owner on Users & Roles → Ownership review.
 */

defined('APP_ROOT') or exit('No direct access.');

const MULTIUSER_MARKER = 'storage/upgrade_multiuser.done';

/** Tables that carry their own owner column, with their label. */
const MULTIUSER_OWNED_TABLES = [
    'borrowers'               => 'Borrowers',
    'penalty_rules'           => 'Penalty rules',
    'agreement_templates'     => 'Agreement templates',
    'demand_letter_templates' => 'Demand letter templates',
    'documents'               => 'Documents',
    'payment_methods'         => 'Payment methods (your receiving accounts)',
];

/** Rows that need an owner of their own (a borrower's payment method belongs to that borrower's owner). */
function multiuser_unowned_where(string $table): string
{
    return 'owner_user_id IS NULL' . ($table === 'payment_methods' ? ' AND borrower_id IS NULL' : '');
}

/** Second pass (payment methods and the creator rule were added later): only fills owners still missing. */
const MULTIUSER_MARKER_V2 = 'storage/upgrade_multiuser_v2.done';

function multiuser_upgrade(): void
{
    if (is_file(APP_ROOT . '/' . MULTIUSER_MARKER)) {
        if (!is_file(APP_ROOT . '/' . MULTIUSER_MARKER_V2)) {
            $result = multiuser_assign_owners();
            $summary = [];
            foreach ($result as $table => [$assigned, $flagged]) {
                $summary[] = MULTIUSER_OWNED_TABLES[$table] . ': ' . $assigned . ' assigned' . ($flagged ? ', ' . $flagged . ' to confirm' : '');
            }
            log_activity('multiuser_upgrade', 'system', null, 'Multi-user owners (second pass). ' . implode('; ', $summary), null, null, SYSTEM_ACTOR);
            @file_put_contents(APP_ROOT . '/' . MULTIUSER_MARKER_V2, date('c') . "\n", LOCK_EX);
        }
        return;
    }
    @set_time_limit(600);   // one-time backup + upgrade of all existing data
    $backup = database_backup('before_multiuser');
    multiuser_schema();
    $result = multiuser_assign_owners();

    $summary = [];
    foreach ($result as $table => [$assigned, $flagged]) {
        $summary[] = MULTIUSER_OWNED_TABLES[$table] . ': ' . $assigned . ' assigned' . ($flagged ? ', ' . $flagged . ' to confirm' : '');
    }
    log_activity('multiuser_upgrade', 'system', null, 'Multi-user upgrade. Backup: ' . basename($backup) . '. ' . implode('; ', $summary), null, null, SYSTEM_ACTOR);
    @file_put_contents(APP_ROOT . '/' . MULTIUSER_MARKER, date('c') . "\n" . $backup, LOCK_EX);
    @file_put_contents(APP_ROOT . '/' . MULTIUSER_MARKER_V2, date('c') . "\n", LOCK_EX);   // this run included the second pass
}

/**
 * The schema change of db/upgrade_multiuser.sql, applied step by step so it
 * works on every MariaDB/MySQL version XAMPP ships: each part is added only
 * when missing, and the new column copies users.id's character set and
 * collation so the foreign key always matches. If the server still refuses
 * the foreign key, the app works without it (ownership is enforced by the
 * application) and the reason is logged.
 */
function multiuser_schema(): void
{
    $users = db_row(
        "SELECT CHARACTER_SET_NAME AS cs, COLLATION_NAME AS co FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'id'"
    );
    $type = 'CHAR(36)' . ($users && $users['cs'] ? ' CHARACTER SET ' . $users['cs'] . ' COLLATE ' . $users['co'] : '');
    $names = [
        'borrowers' => 'borrowers', 'penalty_rules' => 'penalty_rules', 'agreement_templates' => 'agreement_templates',
        'demand_letter_templates' => 'letter_templates', 'documents' => 'documents',
    ];
    foreach ($names as $table => $short) {
        $t = db_identifier($table);
        $has = static fn (string $sql) => (int) db_value($sql, [':t' => $table]) > 0;
        if (!$has("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = 'owner_user_id'")) {
            db()->exec("ALTER TABLE $t ADD COLUMN owner_user_id $type NULL AFTER id");
        }
        if (!$has("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = 'owner_user_id'")) {
            db()->exec("CREATE INDEX idx_{$short}_owner ON $t (owner_user_id)");
        }
        if (!$has("SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t
                    AND COLUMN_NAME = 'owner_user_id' AND REFERENCED_TABLE_NAME = 'users'")) {
            try {
                db()->exec("ALTER TABLE $t ADD CONSTRAINT fk_{$short}_owner FOREIGN KEY (owner_user_id) REFERENCES users (id)");
            } catch (PDOException $e) {
                error_log('[' . APP_NAME . '] Multi-user upgrade: foreign key on ' . $table . '.owner_user_id not added (' . $e->getMessage() . '). The application still enforces ownership.');
            }
        }
    }
}

/**
 * Write every table's rows as INSERT statements to storage/backups/ (works
 * on XAMPP without shell access). Returns the file path.
 */
function database_backup(string $label): string
{
    $dir = APP_ROOT . '/storage/backups';
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
        @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
    }
    $file = $dir . '/' . $label . '_' . date('Ymd_His') . '.sql';
    $out = @fopen($file, 'wb');
    if ($out === false) {
        // No upgrade without a backup: say exactly what to fix.
        throw new UpgradeException('The database upgrade needs a backup first, but the folder storage/backups cannot be written. '
            . 'Make sure the paswipe_me/storage folder exists and is not read-only (right-click → Properties → uncheck Read-only), then reload this page. '
            . 'Nothing in your data was changed.');
    }
    fwrite($out, "-- PA-KASKAS data backup " . date('c') . "\n-- Restore into the same schema: mysql pa_swipe_ako < this file\nSET FOREIGN_KEY_CHECKS = 0;\n");
    foreach (db()->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM) as [$table]) {
        fwrite($out, "\n-- " . $table . "\nDELETE FROM `" . $table . "`;\n");
        $stmt = db()->query('SELECT * FROM `' . $table . '`');
        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            $values = array_map(static fn ($v) => $v === null ? 'NULL' : db()->quote((string) $v), $row);
            fwrite($out, 'INSERT INTO `' . $table . '` (`' . implode('`, `', array_keys($row)) . '`) VALUES (' . implode(', ', $values) . ");\n");
        }
    }
    fwrite($out, "\nSET FOREIGN_KEY_CHECKS = 1;\n");
    fclose($out);

    return $file;
}

/** The one owner of every card, when all cards have the same owner. */
function multiuser_sole_owner(): ?string
{
    $owners = db_all('SELECT DISTINCT owner_user_id FROM credit_cards');

    return count($owners) === 1 ? $owners[0]['owner_user_id'] : null;
}

/**
 * Give each record without an owner the owner its links point to.
 * Returns [table => [assigned, still unassigned]].
 */
function multiuser_assign_owners(): array
{
    $sole = multiuser_sole_owner();
    $result = [];
    $decide = static function (array $candidates, array $creators) use ($sole): ?string {
        $candidates = array_values(array_unique(array_filter($candidates)));
        $creators = array_values(array_unique(array_filter($creators)));
        if (count($candidates) === 1) {
            return $candidates[0];
        }
        if ($candidates) {
            return null;                                     // links to two owners: flag
        }
        if ($creators) {
            return count($creators) === 1 ? $creators[0] : null;
        }

        return $sole;                                        // nothing to follow: the single owner, if any
    };
    // Who created the record, when that user manages records (not a borrower).
    $staff = "(SELECT ur.user_id FROM user_roles ur WHERE ur.role IN ('owner', 'admin'))";
    $creators = [
        'borrowers' => "SELECT created_by FROM borrowers WHERE id = :id AND created_by IN $staff",
        'documents' => "SELECT uploaded_by FROM documents WHERE id = :id AND uploaded_by IN $staff",
    ];
    $rules = [
        'borrowers' => "SELECT DISTINCT c.owner_user_id FROM (
                SELECT t.credit_card_id AS card FROM transactions t WHERE t.borrower_id = :id
                UNION SELECT t.credit_card_id FROM borrower_obligations o JOIN transactions t ON t.id = o.transaction_id WHERE o.borrower_id = :id2
                UNION SELECT l.credit_card_id FROM cash_loan_allocations a JOIN cash_loans l ON l.id = a.cash_loan_id WHERE a.borrower_id = :id3
            ) x JOIN credit_cards c ON c.id = x.card",
        'penalty_rules' => "SELECT DISTINCT b.owner_user_id FROM applied_penalties ap
                JOIN borrower_obligations o ON o.id = ap.obligation_id JOIN borrowers b ON b.id = o.borrower_id
               WHERE ap.penalty_rule_id = :id",
        'agreement_templates' => "SELECT DISTINCT b.owner_user_id FROM agreements a JOIN borrowers b ON b.id = a.borrower_id
               WHERE a.template_id = :id",
        'demand_letter_templates' => "SELECT DISTINCT b.owner_user_id FROM demand_letters d JOIN borrowers b ON b.id = d.borrower_id
               WHERE d.template_id = :id",
        'documents' => "SELECT DISTINCT owner FROM (
                SELECT b.owner_user_id AS owner FROM payment_attachments pa JOIN borrower_payments p ON p.id = pa.borrower_payment_id JOIN borrowers b ON b.id = p.borrower_id WHERE pa.document_id = :id
                UNION SELECT c.owner_user_id FROM transaction_attachments ta JOIN transactions t ON t.id = ta.transaction_id JOIN credit_cards c ON c.id = t.credit_card_id WHERE ta.document_id = :id2
                UNION SELECT b.owner_user_id FROM agreement_documents ad JOIN agreements a ON a.id = ad.agreement_id JOIN borrowers b ON b.id = a.borrower_id WHERE ad.document_id = :id3
                UNION SELECT b.owner_user_id FROM agreements a JOIN borrowers b ON b.id = a.borrower_id WHERE a.generated_document_id = :id4 OR a.signed_document_id = :id5
                UNION SELECT b.owner_user_id FROM demand_letters d JOIN borrowers b ON b.id = d.borrower_id WHERE d.document_id = :id6
                UNION SELECT c.owner_user_id FROM credit_card_statements s JOIN credit_cards c ON c.id = s.credit_card_id WHERE s.attachment_document_id = :id7
                UNION SELECT c.owner_user_id FROM credit_card_payments p JOIN credit_cards c ON c.id = p.credit_card_id WHERE p.receipt_document_id = :id8
            ) x WHERE owner IS NOT NULL",
    ];
    // Owner receiving accounts: the owner of the cards / borrowers whose payments used them.
    $rules['payment_methods'] = "SELECT DISTINCT owner FROM (
            SELECT c.owner_user_id AS owner FROM credit_card_payments p JOIN credit_cards c ON c.id = p.credit_card_id WHERE p.payment_method_id = :id
            UNION SELECT b.owner_user_id FROM borrower_payments p JOIN borrowers b ON b.id = p.borrower_id WHERE p.payment_method_id = :id2
        ) x WHERE owner IS NOT NULL";

    $params = static function (string $sql, string $id): array {
        // Every :id, :id2 … placeholder of the query gets the record's id.
        preg_match_all('/:id\d*/', $sql, $m);

        return array_fill_keys(array_unique($m[0]), $id);
    };
    $column = static fn (array $rows) => array_map(static fn ($r) => reset($r), $rows);

    db_transaction(static function () use ($rules, $creators, $decide, $params, $column, &$result) {
        foreach ($rules as $table => $sql) {   // borrowers first: the others follow them
            $assigned = 0;
            $flagged = 0;
            foreach (db_all('SELECT id FROM ' . db_identifier($table) . ' WHERE ' . multiuser_unowned_where($table)) as $row) {
                $owner = $decide(
                    $column(db_all($sql, $params($sql, $row['id']))),
                    isset($creators[$table]) ? $column(db_all($creators[$table], [':id' => $row['id']])) : []
                );
                if ($owner) {
                    db_exec('UPDATE ' . db_identifier($table) . ' SET owner_user_id = :o WHERE id = :id', [':o' => $owner, ':id' => $row['id']]);
                    $assigned++;
                } else {
                    $flagged++;
                }
            }
            $result[$table] = [$assigned, $flagged];
        }
    });

    return $result;
}

/** Records still waiting for an owner: [table => count]. */
function multiuser_unassigned_counts(): array
{
    $counts = [];
    foreach (MULTIUSER_OWNED_TABLES as $table => $label) {
        $counts[$table] = (int) db_value('SELECT COUNT(*) FROM ' . db_identifier($table) . ' WHERE ' . multiuser_unowned_where($table));
    }

    return $counts;
}

/** Records waiting for an owner, with a short label: [table => rows]. */
function multiuser_unassigned_records(): array
{
    $labels = [
        'borrowers'               => "CONCAT(borrower_number, ' — ', first_name, ' ', last_name)",
        'penalty_rules'           => 'name',
        'agreement_templates'     => "CONCAT(name, ' (v', version, ')')",
        'demand_letter_templates' => "CONCAT(name, ' (v', version, ')')",
        'documents'               => "CONCAT(original_filename, ' · ', document_type)",
        'payment_methods'         => "CONCAT(display_name, ' · ', method_type)",
    ];
    $records = [];
    foreach ($labels as $table => $label) {
        $records[$table] = db_all('SELECT id, ' . $label . ' AS label FROM ' . db_identifier($table) . ' WHERE ' . multiuser_unowned_where($table) . ' ORDER BY label');
    }

    return $records;
}

/** Users who can own records (owner or administrator accounts that are active). */
function multiuser_owner_options(): array
{
    $options = [];
    foreach (db_all(
        "SELECT DISTINCT u.id, u.first_name, u.last_name, u.username, u.email FROM users u
           JOIN user_roles r ON r.user_id = u.id AND r.role IN ('owner', 'admin')
          WHERE u.status = 'active' ORDER BY u.last_name, u.first_name, u.email"
    ) as $u) {
        $options[$u['id']] = user_display_name($u) . ' (' . $u['email'] . ')';
    }

    return $options;
}

/**
 * Confirm the owner of a flagged record. Only records still without an owner
 * can be assigned here - an owned record never changes hands this way.
 */
function multiuser_assign(string $table, string $id, string $userId): bool
{
    if (!isset(MULTIUSER_OWNED_TABLES[$table]) || !is_valid_uuid($id) || !isset(multiuser_owner_options()[$userId])) {
        return false;
    }
    $changed = db_exec('UPDATE ' . db_identifier($table) . ' SET owner_user_id = :u WHERE id = :id AND ' . multiuser_unowned_where($table), [':u' => $userId, ':id' => $id]);
    if ($changed) {
        log_activity('ownership_confirmed', 'system', $id, 'Owner confirmed for ' . $table . ' record ' . $id, null, ['owner_user_id' => $userId]);
    }

    return $changed > 0;
}
