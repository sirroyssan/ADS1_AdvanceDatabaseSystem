<?php
/**
 * Reset test financial data of ONE owner (the signed-in user) so recording can
 * start from zero, keeping master data and configuration.
 *
 * Removed (only rows belonging to the owner's cards and borrowers, found through
 * their links - never by table name alone):
 *   transactions with their items, attachments, installment plans/schedules;
 *   cash loans with allocations and schedules; cash advances; bank charges
 *   (except the card's opening balance); card payments; borrower obligations,
 *   borrower payments with their allocations, selections, verifications and
 *   attachment links; applied penalties; agreements and demand letters linked to
 *   removed obligations/transactions; statements the app prepared (statement_items
 *   of the owner's cards are rebuilt); notifications about removed records.
 * Kept: users, roles, credit cards (cutoff, due-date rule, limit, opening
 *   balance), card_billing_cycles, borrowers and their contacts/addresses/portal
 *   accounts, penalty rules, installment rules, payment methods, categories,
 *   merchants, templates, recorded bank SOAs (and their reconciliations),
 *   activity logs.
 * Files: documents used only by removed rows are flagged is_deleted (files stay).
 *
 * Safety: every removed row is first copied as JSON into backup_reset_rows
 * (same database transaction as the deletes), deletes run children first with
 * foreign-key checks ON, and the whole reset commits or rolls back as one.
 * data_reset_export_sql() turns a backup batch back into restorable SQL.
 */

defined('APP_ROOT') or exit('No direct access.');

/** Delete order: every table comes after the tables that reference it. */
const DATA_RESET_ORDER = [
    'payment_verification_logs', 'payment_verifications', 'payment_attachments',
    'borrower_payment_allocations', 'borrower_payment_selections', 'borrower_payment_purchase_parts', 'applied_penalties', 'notification_logs', 'notifications',
    'demand_letters', 'agreement_signatories', 'agreement_documents', 'agreements',
    'borrower_payments', 'borrower_obligations',
    'cash_loan_schedules', 'cash_loan_allocations', 'cash_loans',
    'installment_schedules', 'installment_plans',
    'transaction_items', 'transaction_attachments', 'transactions',
    'statement_items', 'statement_reconciliations',
    'cash_advances', 'bank_charges', 'credit_card_payments', 'credit_card_statements',
];

/** Master data and configuration that must be identical before and after. */
const DATA_RESET_PRESERVED = [
    'users'                  => 'SELECT COUNT(*) FROM users',
    'user_roles'             => 'SELECT COUNT(*) FROM user_roles',
    'credit_cards'           => 'SELECT COUNT(*) FROM credit_cards WHERE owner_user_id = :u',
    'card_billing_cycles'    => 'SELECT COUNT(*) FROM card_billing_cycles WHERE credit_card_id IN (SELECT id FROM credit_cards WHERE owner_user_id = :u)',
    'bank_charges (opening balances)' => "SELECT COUNT(*) FROM bank_charges WHERE charge_type = '" . OPENING_BALANCE_TYPE . "' AND credit_card_id IN (SELECT id FROM credit_cards WHERE owner_user_id = :u)",
    'credit_card_statements (recorded SOAs)' => 'SELECT COUNT(*) FROM credit_card_statements WHERE soa_recorded_at IS NOT NULL AND credit_card_id IN (SELECT id FROM credit_cards WHERE owner_user_id = :u)',
    'borrowers'              => 'SELECT COUNT(*) FROM borrowers WHERE owner_user_id = :u',
    'borrower_addresses'     => 'SELECT COUNT(*) FROM borrower_addresses WHERE borrower_id IN (SELECT id FROM borrowers WHERE owner_user_id = :u)',
    'borrower_contacts'      => 'SELECT COUNT(*) FROM borrower_contacts WHERE borrower_id IN (SELECT id FROM borrowers WHERE owner_user_id = :u)',
    'borrower_user_accounts' => 'SELECT COUNT(*) FROM borrower_user_accounts WHERE borrower_id IN (SELECT id FROM borrowers WHERE owner_user_id = :u)',
    'penalty_rules'          => 'SELECT COUNT(*) FROM penalty_rules WHERE owner_user_id = :u',
    'installment_rules'      => 'SELECT COUNT(*) FROM installment_rules WHERE owner_user_id = :u',
    'payment_methods'        => 'SELECT COUNT(*) FROM payment_methods WHERE owner_user_id = :u OR borrower_id IN (SELECT id FROM borrowers WHERE owner_user_id = :u2)',
    'categories'             => 'SELECT COUNT(*) FROM categories',
    'merchants'              => 'SELECT COUNT(*) FROM merchants',
    'agreement_templates'    => 'SELECT COUNT(*) FROM agreement_templates WHERE owner_user_id = :u',
    'demand_letter_templates' => 'SELECT COUNT(*) FROM demand_letter_templates WHERE owner_user_id = :u',
];

/** Columns that point at documents, checked before a document is flagged deleted. */
const DATA_RESET_DOCUMENT_REFS = [
    ['transaction_attachments', 'document_id'], ['payment_attachments', 'document_id'], ['agreement_documents', 'document_id'],
    ['agreements', 'generated_document_id'], ['agreements', 'signed_document_id'], ['demand_letters', 'document_id'],
    ['credit_card_payments', 'receipt_document_id'], ['credit_card_statements', 'attachment_document_id'],
];

/** "IN (...)" list of ids; an empty list matches nothing. */
function data_reset_in(array $ids): string
{
    return $ids ? sql_in_list(array_values(array_unique($ids))) : 'NULL';
}

function data_reset_ids(string $sql, array $params = []): array
{
    return array_column(db_all($sql, $params), 'id');
}

/**
 * The rows in reset scope for one owner: [table => [id, ...]] in delete order,
 * plus 'documents' (to flag) and 'cards' (to recalculate).
 */
function data_reset_scope(string $ownerId): array
{
    $u = [':u' => $ownerId];
    $cards = data_reset_ids('SELECT id FROM credit_cards WHERE owner_user_id = :u', $u);
    $borrowers = data_reset_ids('SELECT id FROM borrowers WHERE owner_user_id = :u', $u);
    $c = data_reset_in($cards);
    $b = data_reset_in($borrowers);

    $s = [];
    $s['transactions'] = data_reset_ids("SELECT id FROM transactions WHERE credit_card_id IN ($c) OR borrower_id IN ($b)");
    $t = data_reset_in($s['transactions']);
    $s['installment_plans'] = data_reset_ids("SELECT id FROM installment_plans WHERE transaction_id IN ($t)");
    $s['installment_schedules'] = data_reset_ids('SELECT id FROM installment_schedules WHERE installment_plan_id IN (' . data_reset_in($s['installment_plans']) . ')');
    $sch = data_reset_in($s['installment_schedules']);
    $s['transaction_items'] = data_reset_ids("SELECT id FROM transaction_items WHERE transaction_id IN ($t)");
    $s['transaction_attachments'] = data_reset_ids("SELECT id FROM transaction_attachments WHERE transaction_id IN ($t)");

    $s['cash_loans'] = data_reset_ids("SELECT id FROM cash_loans WHERE credit_card_id IN ($c)");
    $l = data_reset_in($s['cash_loans']);
    $s['cash_loan_allocations'] = data_reset_ids("SELECT id FROM cash_loan_allocations WHERE cash_loan_id IN ($l)");
    $s['cash_loan_schedules'] = data_reset_ids("SELECT id FROM cash_loan_schedules WHERE cash_loan_id IN ($l)");

    $s['borrower_obligations'] = data_reset_ids(
        "SELECT id FROM borrower_obligations WHERE borrower_id IN ($b) OR transaction_id IN ($t)
            OR cash_loan_allocation_id IN (" . data_reset_in($s['cash_loan_allocations']) . ')'
    );
    $o = data_reset_in($s['borrower_obligations']);
    $s['borrower_payments'] = data_reset_ids("SELECT id FROM borrower_payments WHERE borrower_id IN ($b) OR transaction_id IN ($t)");
    $p = data_reset_in($s['borrower_payments']);
    $s['agreements'] = data_reset_ids("SELECT id FROM agreements WHERE obligation_id IN ($o) OR transaction_id IN ($t)");
    $a = data_reset_in($s['agreements']);
    $s['agreement_signatories'] = data_reset_ids("SELECT id FROM agreement_signatories WHERE agreement_id IN ($a)");
    $s['agreement_documents'] = data_reset_ids("SELECT id FROM agreement_documents WHERE agreement_id IN ($a)");
    $s['demand_letters'] = data_reset_ids("SELECT id FROM demand_letters WHERE obligation_id IN ($o) OR agreement_id IN ($a)");

    $s['applied_penalties'] = data_reset_ids("SELECT id FROM applied_penalties WHERE obligation_id IN ($o) OR transaction_id IN ($t) OR installment_schedule_id IN ($sch)");
    $s['borrower_payment_allocations'] = data_reset_ids(
        "SELECT id FROM borrower_payment_allocations WHERE borrower_payment_id IN ($p) OR obligation_id IN ($o) OR installment_schedule_id IN ($sch)"
    );
    $s['borrower_payment_selections'] = data_reset_ids(
        "SELECT id FROM borrower_payment_selections WHERE borrower_payment_id IN ($p) OR obligation_id IN ($o) OR installment_schedule_id IN ($sch)"
    );
    $s['borrower_payment_purchase_parts'] = data_reset_ids(
        "SELECT id FROM borrower_payment_purchase_parts WHERE borrower_payment_id IN ($p) OR transaction_id IN ($t) OR installment_schedule_id IN ($sch)"
    );
    $s['payment_verifications'] = data_reset_ids("SELECT id FROM payment_verifications WHERE borrower_payment_id IN ($p)");
    $s['payment_verification_logs'] = data_reset_ids("SELECT id FROM payment_verification_logs WHERE borrower_payment_id IN ($p)");
    $s['payment_attachments'] = data_reset_ids("SELECT id FROM payment_attachments WHERE borrower_payment_id IN ($p)");

    $s['cash_advances'] = data_reset_ids("SELECT id FROM cash_advances WHERE credit_card_id IN ($c)");
    $s['bank_charges'] = data_reset_ids("SELECT id FROM bank_charges WHERE credit_card_id IN ($c) AND charge_type <> :t", [':t' => OPENING_BALANCE_TYPE]);
    $s['credit_card_payments'] = data_reset_ids("SELECT id FROM credit_card_payments WHERE credit_card_id IN ($c)");
    // Statements the app prepared; the bank SOAs you recorded stay. Line items are rebuilt afterwards.
    $s['credit_card_statements'] = data_reset_ids("SELECT id FROM credit_card_statements WHERE credit_card_id IN ($c) AND soa_recorded_at IS NULL");
    $s['statement_items'] = data_reset_ids("SELECT id FROM statement_items WHERE credit_card_id IN ($c)");
    $s['statement_reconciliations'] = data_reset_ids('SELECT id FROM statement_reconciliations WHERE statement_id IN (' . data_reset_in($s['credit_card_statements']) . ')');

    // Notifications about any removed record (to the owner or to a borrower's portal account).
    $removed = array_merge(...array_values($s));
    $s['notifications'] = $removed ? data_reset_ids('SELECT id FROM notifications WHERE entity_id IN (' . data_reset_in($removed) . ')') : [];
    $s['notification_logs'] = data_reset_ids('SELECT id FROM notification_logs WHERE notification_id IN (' . data_reset_in($s['notifications']) . ')');

    $scope = [];
    foreach (DATA_RESET_ORDER as $table) {
        $scope[$table] = $s[$table] ?? [];
    }

    // Documents linked only from removed rows (flagged deleted, file kept).
    $docs = [];
    foreach (DATA_RESET_DOCUMENT_REFS as [$table, $column]) {
        if ($scope[$table]) {
            $docs = array_merge($docs, array_column(db_all(
                'SELECT ' . db_identifier($column) . ' AS id FROM ' . db_identifier($table) . ' WHERE ' . db_identifier($column) . ' IS NOT NULL AND id IN (' . data_reset_in($scope[$table]) . ')'
            ), 'id'));
        }
    }
    $scope['documents'] = array_values(array_unique($docs));
    $scope['cards'] = $cards;

    return $scope;
}

/** Row counts per table in scope (no changes). */
function data_reset_counts(array $scope): array
{
    $counts = [];
    foreach (DATA_RESET_ORDER as $table) {
        $counts[$table] = count($scope[$table]);
    }

    return $counts;
}

function data_reset_preserved_counts(string $ownerId): array
{
    $out = [];
    foreach (DATA_RESET_PRESERVED as $label => $sql) {
        // Only the placeholders the query uses (PDO refuses extra ones).
        $params = array_filter([':u' => $ownerId, ':u2' => $ownerId], static fn ($k) => preg_match('/' . $k . '\b/', $sql), ARRAY_FILTER_USE_KEY);
        $out[$label] = (int) db_value($sql, $params);
    }

    return $out;
}

/** The backup table (created once, outside any transaction: DDL commits implicitly). */
function data_reset_ensure_backup_table(): void
{
    db()->exec(
        'CREATE TABLE IF NOT EXISTS backup_reset_rows (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            batch VARCHAR(30) NOT NULL,
            owner_user_id CHAR(36) NOT NULL,
            table_name VARCHAR(64) NOT NULL,
            row_id VARCHAR(64) NOT NULL,
            row_json LONGTEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_backup_reset_batch (batch, table_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

/**
 * Run the reset for $ownerId. Returns the report:
 * [batch, removed => [table => n], documents_flagged, preserved_before, preserved_after, cards => [...], issues => [...]].
 */
function data_reset_run(string $ownerId, string $actorId): array
{
    data_reset_ensure_backup_table();
    $batch = 'reset_' . date('Ymd_His');
    $preservedBefore = data_reset_preserved_counts($ownerId);

    $result = db_transaction(static function () use ($ownerId, $batch) {
        $cards = data_reset_ids('SELECT id FROM credit_cards WHERE owner_user_id = :u ORDER BY id FOR UPDATE', [':u' => $ownerId]);
        // Scope is read inside the transaction with the cards locked, so nothing is added meanwhile.
        $scope = data_reset_scope($ownerId);

        // 1. Back up every row first (before any change, e.g. the allocation link below).
        foreach (array_merge(DATA_RESET_ORDER, ['documents']) as $table) {
            foreach (array_chunk($scope[$table], 500) as $chunk) {
                foreach (db_all('SELECT * FROM ' . db_identifier($table) . ' WHERE id IN (' . data_reset_in($chunk) . ')') as $row) {
                    db_insert('backup_reset_rows', [
                        'batch' => $batch, 'owner_user_id' => $ownerId, 'table_name' => $table, 'row_id' => (string) $row['id'],
                        'row_json' => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    ]);
                }
            }
        }

        // 2. Break the one circular link (allocation <-> obligation), then delete children first.
        if ($scope['cash_loan_allocations']) {
            db()->exec('UPDATE cash_loan_allocations SET obligation_id = NULL WHERE id IN (' . data_reset_in($scope['cash_loan_allocations']) . ')');
        }
        $removed = [];
        foreach (DATA_RESET_ORDER as $table) {
            $removed[$table] = 0;
            foreach (array_chunk($scope[$table], 500) as $chunk) {
                $removed[$table] += db()->exec('DELETE FROM ' . db_identifier($table) . ' WHERE id IN (' . data_reset_in($chunk) . ')');
            }
        }

        // 3. Documents no longer linked from anywhere are hidden (files stay on disk and in the backup).
        $flagged = 0;
        if ($scope['documents']) {
            $notLinked = implode(' AND ', array_map(static fn ($r) => 'NOT EXISTS (SELECT 1 FROM ' . db_identifier($r[0]) . ' x WHERE x.' . db_identifier($r[1]) . ' = d.id)', DATA_RESET_DOCUMENT_REFS));
            $flagged = db()->exec('UPDATE documents d SET is_deleted = 1 WHERE d.is_deleted = 0 AND d.id IN (' . data_reset_in($scope['documents']) . ") AND $notLinked");
        }

        // 4. Stored available credit recalculated from what remains (not set blindly).
        card_usage_sync($cards);

        return ['removed' => $removed, 'documents_flagged' => $flagged, 'cards' => $cards];
    });

    // 5. Prepared statements rebuilt from the remaining records (each card in its own transaction).
    foreach ($result['cards'] as $cardId) {
        statement_prepare_card($cardId);
    }

    $report = ['batch' => $batch] + $result + [
        'preserved_before' => $preservedBefore,
        'preserved_after'  => data_reset_preserved_counts($ownerId),
    ];
    $report['issues'] = data_reset_verify($ownerId, $report);
    $report['card_usage'] = [];
    foreach ($result['cards'] as $cardId) {
        $card = db_row('SELECT card_nickname, last_four, credit_limit, available_credit FROM credit_cards WHERE id = :id', [':id' => $cardId]);
        $report['card_usage'][] = $card + card_usage($cardId) + ['opening' => (float) (card_opening_balance($cardId)['amount'] ?? 0)];
    }

    log_activity('reset_test_data', 'system', null, 'Test financial data reset (backup batch ' . $batch . '): ' . array_sum($result['removed']) . ' rows removed', null,
        ['removed' => array_filter($result['removed']), 'documents_flagged' => $result['documents_flagged']], $actorId);

    return $report;
}

/** Problems left after a reset (empty = clean). */
function data_reset_verify(string $ownerId, array $report): array
{
    $issues = [];
    // Prepared statements and their lines are rebuilt from the remaining records, so they may exist again.
    $left = array_filter(data_reset_counts(data_reset_scope($ownerId)), static fn ($n, $t) => $n > 0 && !in_array($t, ['statement_items', 'credit_card_statements'], true), ARRAY_FILTER_USE_BOTH);
    foreach ($left as $table => $n) {
        $issues[] = "$table: $n record(s) still in scope.";
    }
    foreach ($report['preserved_before'] as $label => $n) {
        if ($report['preserved_after'][$label] !== $n) {
            $issues[] = "$label changed from $n to {$report['preserved_after'][$label]} (should be preserved).";
        }
    }
    // Rows without a foreign key that could point at removed records.
    $cards = data_reset_in(data_reset_ids('SELECT id FROM credit_cards WHERE owner_user_id = :u', [':u' => $ownerId]));
    $orphanItems = (int) db_value(
        "SELECT COUNT(*) FROM statement_items si WHERE si.credit_card_id IN ($cards) AND NOT (
            (si.source_type = 'transaction' AND EXISTS (SELECT 1 FROM transactions x WHERE x.id = si.source_id))
         OR (si.source_type = 'installment_schedule' AND EXISTS (SELECT 1 FROM installment_schedules x WHERE x.id = si.source_id))
         OR (si.source_type = 'cash_loan_schedule' AND EXISTS (SELECT 1 FROM cash_loan_schedules x WHERE x.id = si.source_id))
         OR (si.source_type IN ('cash_advance', 'cash_advance_fee', 'cash_advance_interest') AND EXISTS (SELECT 1 FROM cash_advances x WHERE x.id = si.source_id))
         OR (si.source_type = 'bank_charge' AND EXISTS (SELECT 1 FROM bank_charges x WHERE x.id = si.source_id))
         OR (si.source_type = 'card_payment' AND EXISTS (SELECT 1 FROM credit_card_payments x WHERE x.id = si.source_id)))"
    );
    if ($orphanItems > 0) {
        $issues[] = "statement_items: $orphanItems line(s) point at records that no longer exist.";
    }
    $orphanNotes = (int) db_value(
        "SELECT COUNT(*) FROM notifications n WHERE n.entity_id IS NOT NULL AND n.entity_id IN (SELECT row_id FROM backup_reset_rows WHERE batch = :b AND table_name <> 'notifications')",
        [':b' => $report['batch']]
    );
    if ($orphanNotes > 0) {
        $issues[] = "notifications: $orphanNotes still refer to removed records.";
    }

    return $issues;
}

function data_reset_batches(string $ownerId): array
{
    try {
        return db_all('SELECT batch, COUNT(*) AS rows_saved, MIN(created_at) AS created_at FROM backup_reset_rows WHERE owner_user_id = :u GROUP BY batch ORDER BY batch DESC', [':u' => $ownerId]);
    } catch (PDOException) {
        return [];   // no reset has run yet (table not created)
    }
}

/**
 * Restorable SQL for one backup batch: parents first, the allocation/obligation
 * link restored after both exist, flagged documents shown again.
 */
function data_reset_export_sql(string $ownerId, string $batch): ?string
{
    $rows = db_all('SELECT table_name, row_json FROM backup_reset_rows WHERE owner_user_id = :u AND batch = :b ORDER BY id', [':u' => $ownerId, ':b' => $batch]);
    if (!$rows) {
        return null;
    }
    $byTable = [];
    foreach ($rows as $r) {
        $byTable[$r['table_name']][] = json_decode($r['row_json'], true);
    }
    $pdo = db();
    $value = static fn ($v) => $v === null ? 'NULL' : $pdo->quote((string) $v);
    $out = ['-- ' . APP_NAME . ' test data reset backup ' . $batch . ' (' . date('c') . ')',
            '-- Restores the removed rows exactly (cash loan allocations linked to an obligation get a new updated_at).',
            '-- Run it on the same database, e.g. in phpMyAdmin > SQL. Prepared statements made since the reset are replaced.',
            'SET NAMES utf8mb4;',   // text (₱, —) comes back exactly in any client
            'START TRANSACTION;'];
    // Prepared statements are rebuilt by the app; the ones made after the reset give way to the restored ones.
    $cards = array_values(array_unique(array_column(array_merge($byTable['credit_card_statements'] ?? [], $byTable['statement_items'] ?? []), 'credit_card_id')));
    if ($cards) {
        $list = implode(', ', array_map($value, $cards));
        $out[] = "DELETE FROM statement_items WHERE credit_card_id IN ($list);";
        $out[] = "DELETE FROM credit_card_statements WHERE credit_card_id IN ($list) AND soa_recorded_at IS NULL;";
    }
    $links = [];
    foreach (array_reverse(DATA_RESET_ORDER) as $table) {
        foreach ($byTable[$table] ?? [] as $row) {
            if ($table === 'cash_loan_allocations' && $row['obligation_id'] !== null) {
                // Linked again once the obligation exists (its BEFORE UPDATE trigger stamps updated_at with the restore time).
                $links[] = 'UPDATE cash_loan_allocations SET obligation_id = ' . $value($row['obligation_id']) . ' WHERE id = ' . $value($row['id']) . ';';
                $row['obligation_id'] = null;   // the obligation is inserted after its allocation
            }
            $out[] = 'INSERT INTO `' . $table . '` (`' . implode('`, `', array_keys($row)) . '`) VALUES (' . implode(', ', array_map($value, $row)) . ');';
        }
        if ($table === 'borrower_obligations') {
            array_push($out, ...$links);
            $links = [];
        }
    }
    array_push($out, ...$links);
    foreach ($byTable['documents'] ?? [] as $row) {
        $out[] = 'UPDATE documents SET is_deleted = ' . (int) $row['is_deleted'] . ' WHERE id = ' . $value($row['id']) . ';';
    }
    $out[] = 'COMMIT;';

    return implode("\n", $out) . "\n";
}
